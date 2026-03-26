package org.fossify.phone.services

import android.net.Uri
import android.telecom.Call
import android.telecom.CallAudioState
import android.telecom.InCallService
import org.fossify.commons.extensions.canUseFullScreenIntent
import org.fossify.commons.extensions.hasPermission
import org.fossify.commons.helpers.PERMISSION_POST_NOTIFICATIONS
import org.fossify.phone.activities.CallActivity
import android.util.Log
import org.fossify.phone.extensions.config
import org.fossify.phone.extensions.getStateCompat
import org.fossify.phone.extensions.isGatewayNumber
import org.fossify.phone.extensions.isOutgoing
import org.fossify.phone.extensions.keyguardManager
import org.fossify.phone.extensions.powerManager
import org.fossify.phone.helpers.CallManager
import org.fossify.phone.helpers.CallMetadataBridge
import org.fossify.phone.helpers.CallNotificationManager
import org.fossify.phone.helpers.NoCall
import org.fossify.phone.models.Events
import org.greenrobot.eventbus.EventBus

class CallService : InCallService() {
    private val callNotificationManager by lazy { CallNotificationManager(this) }

    private val callListener = object : Call.Callback() {
        override fun onStateChanged(call: Call, state: Int) {
            super.onStateChanged(call, state)
            if (state == Call.STATE_DISCONNECTED || state == Call.STATE_DISCONNECTING) {
                callNotificationManager.cancelNotification()
            } else {
                callNotificationManager.setupNotification()
            }
        }

        override fun onDetailsChanged(call: Call, details: Call.Details) {
            super.onDetailsChanged(call, details)
            // MIUI/Telecom often deliver the real tel: handle after the first frame (was empty/wrong).
            tryApplyIncomingSmsBridge(call)
            if (call.getStateCompat() != Call.STATE_DISCONNECTED && call.getStateCompat() != Call.STATE_DISCONNECTING) {
                callNotificationManager.setupNotification()
            }
        }
    }

    /** Strip bidi marks and decode %20 etc. so isGatewayNumber matches +79219729637 reliably. */
    private fun sanitizeTelecomHandle(raw: String): String {
        return Uri.decode(raw.trim())
            .replace(Regex("[\\u200E\\u200F\\u202A-\\u202E\\u2066-\\u2069]"), "")
            .trim()
    }

    /**
     * One attempt to pull A-party from presignal SMS when this call is the gateway line.
     * Safe to call again from onDetailsChanged if the handle was not ready in onCallAdded.
     */
    private fun tryApplyIncomingSmsBridge(call: Call) {
        if (call.isOutgoing()) return
        if (CallManager.getPrimaryCall() != call) return
        if (CallManager.bridgedCallerNumber != null) return
        val number = sanitizeTelecomHandle(call.details?.handle?.schemeSpecificPart.orEmpty())
        if (!isGatewayNumber(number)) return
        val realCaller = CallMetadataBridge.fetchIncoming() ?: return
        CallManager.bridgedCallerNumber = realCaller
        Log.d("ForeignGSM", "Incoming bridge activated")
        CallManager.notifyBridgeMetadataUpdated()
    }

    override fun onCallAdded(call: Call) {
        super.onCallAdded(call)
        CallManager.onCallAdded(call)
        CallManager.inCallService = this
        call.registerCallback(callListener)

        val number = sanitizeTelecomHandle(call.details?.handle?.schemeSpecificPart.orEmpty())

        tryApplyIncomingSmsBridge(call)

        // Outgoing bridge: call to gateway, show real destination in UI
        if (call.isOutgoing() && isGatewayNumber(number)) {
            val realDest = CallMetadataBridge.fetchOutgoing()
            if (realDest != null) {
                CallManager.bridgedCallerNumber = realDest
                CallManager.bridgedDestinationNumber = realDest
                Log.d("ForeignGSM", "Outgoing bridge activated")
            }
        }

        // Incoming/Outgoing (locked): high priority (FSI)
        // Incoming (unlocked): if user opted in, low priority ➜ manual activity start, otherwise high priority (FSI)
        // Outgoing (unlocked): low priority ➜ manual activity start
        val isIncoming = !call.isOutgoing()
        val isDeviceLocked = !powerManager.isInteractive || keyguardManager.isDeviceLocked
        val lowPriority = when {
            isIncoming && isDeviceLocked -> false
            !isIncoming && isDeviceLocked -> false
            isIncoming && !isDeviceLocked -> config.alwaysShowFullscreen
            else -> true
        }

        callNotificationManager.setupNotification(lowPriority)
        if (
            lowPriority
            || !hasPermission(PERMISSION_POST_NOTIFICATIONS)
            || !canUseFullScreenIntent()
        ) {
            try {
                startActivity(CallActivity.getStartIntent(this))
            } catch (_: Exception) {
                // seems like startActivity can throw AndroidRuntimeException and
                // ActivityNotFoundException, not yet sure when and why, lets show a notification
                callNotificationManager.setupNotification()
            }
        }
    }

    override fun onCallRemoved(call: Call) {
        super.onCallRemoved(call)
        call.unregisterCallback(callListener)
        val wasPrimaryCall = call == CallManager.getPrimaryCall()
        CallManager.onCallRemoved(call)
        if (CallManager.getPhoneState() == NoCall) {
            CallManager.inCallService = null
            callNotificationManager.cancelNotification()
            CallMetadataBridge.clear()
        } else {
            callNotificationManager.setupNotification()
            if (wasPrimaryCall) {
                startActivity(CallActivity.getStartIntent(this))
            }
        }

        EventBus.getDefault().post(Events.RefreshCallLog)
    }

    override fun onCallAudioStateChanged(audioState: CallAudioState?) {
        super.onCallAudioStateChanged(audioState)
        if (audioState != null) {
            CallManager.onAudioStateChanged(audioState)
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        callNotificationManager.cancelNotification()
    }
}
