package org.fossify.phone.receivers

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import android.util.Log
import org.fossify.phone.extensions.config
import org.fossify.phone.extensions.isConference
import org.fossify.phone.extensions.isGatewayNumber
import org.fossify.phone.extensions.isOutgoing
import org.fossify.phone.helpers.CallManager
import org.fossify.phone.helpers.CallMetadataBridge
import org.fossify.phone.helpers.GatewaySmsBodyParser

class GatewaySmsReceiver : BroadcastReceiver() {
    companion object {
        private const val TAG = "ForeignGSM"

        private fun maskForLog(s: String): String {
            val d = s.filter { it.isDigit() }
            if (d.length <= 4) return "****"
            return "***${d.takeLast(4)}"
        }
    }

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return
        if (context.config.gatewayBNumber.isBlank()) {
            Log.d(TAG, "gateway SMS ignored: gateway_b_number not set")
            return
        }

        val messages = Telephony.Sms.Intents.getMessagesFromIntent(intent) ?: return
        if (messages.isEmpty()) return

        val sender = messages.firstOrNull()?.originatingAddress ?: return
        val fullBody = messages.joinToString("") { it.messageBody.orEmpty() }
        if (fullBody.isBlank()) return

        val token = context.config.gatewayPresignalToken.trim()
        val useBodyToken = token.isNotEmpty()
        if (useBodyToken) {
            if (!fullBody.contains(token, ignoreCase = true)) {
                Log.d(TAG, "SMS ignored: presignal token not in body (sender=${maskForLog(sender)})")
                return
            }
        } else {
            if (!context.isGatewayNumber(sender)) {
                Log.d(TAG, "SMS sender=${maskForLog(sender)} not gateway (configured aliases=${context.config.gatewayNumbersList().size})")
                return
            }
        }

        val realNumber = GatewaySmsBodyParser.extractFirstPhone(fullBody)
        if (realNumber == null) {
            Log.d(TAG, "gateway SMS body has no phone-like token (len=${fullBody.length})")
            return
        }
        Log.d(TAG, "gateway SMS: stored bridge caller (len=${realNumber.length})")
        CallMetadataBridge.storeIncoming(realNumber)
        // Call may have started before SMS: refresh in-call UI as soon as presignal arrives.
        applyPresignalToActiveIncomingCall(realNumber)
    }

    private fun applyPresignalToActiveIncomingCall(realNumber: String) {
        val call = CallManager.getPrimaryCall() ?: return
        if (call.isOutgoing() || call.isConference()) return
        CallManager.bridgedCallerNumber = realNumber
        Log.d(TAG, "presignal during ring/talk: show A-party from SMS")
        CallManager.notifyBridgeMetadataUpdated()
    }
}
