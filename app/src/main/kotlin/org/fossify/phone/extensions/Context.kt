package org.fossify.phone.extensions

import android.annotation.SuppressLint
import android.app.Activity
import android.app.KeyguardManager
import android.content.Context
import android.content.Context.KEYGUARD_SERVICE
import android.content.Intent
import android.media.AudioManager
import android.net.Uri
import android.os.PowerManager
import android.telecom.TelecomManager
import android.telephony.PhoneNumberUtils
import android.telephony.SmsManager
import android.util.Log
import org.fossify.commons.extensions.launchActivityIntent
import org.fossify.commons.extensions.telecomManager
import org.fossify.commons.helpers.KEY_PHONE
import org.fossify.commons.helpers.ensureBackgroundThread
import org.fossify.phone.helpers.Config
import org.fossify.phone.models.SIMAccount

val Context.config: Config get() = Config.newInstance(applicationContext)

val Context.audioManager: AudioManager
    get() = getSystemService(Context.AUDIO_SERVICE) as AudioManager

val Context.powerManager: PowerManager
    get() = getSystemService(Context.POWER_SERVICE) as PowerManager

val Context.keyguardManager: KeyguardManager
    get() = getSystemService(KEYGUARD_SERVICE) as KeyguardManager

@SuppressLint("MissingPermission")
fun Context.getAvailableSIMCardLabels(): List<SIMAccount> {
    val simAccounts = mutableListOf<SIMAccount>()
    try {
        telecomManager.callCapablePhoneAccounts.forEachIndexed { index, account ->
            val phoneAccount = telecomManager.getPhoneAccount(account)
            var label = phoneAccount.label.toString()
            var address = phoneAccount.address.toString()
            if (address.startsWith("tel:") && address.substringAfter("tel:").isNotEmpty()) {
                address = Uri.decode(address.substringAfter("tel:"))
                label += " ($address)"
            }

            simAccounts.add(
                SIMAccount(
                    id = index + 1,
                    handle = phoneAccount.accountHandle,
                    label = label,
                    phoneNumber = address.substringAfter("tel:"),
                    color = phoneAccount.highlightColor
                )
            )
        }
    } catch (ignored: Exception) {
    }

    return simAccounts
}

@SuppressLint("MissingPermission")
fun Context.areMultipleSIMsAvailable(): Boolean {
    return try {
        telecomManager.callCapablePhoneAccounts.size > 1
    } catch (ignored: Exception) {
        false
    }
}

fun Context.clearMissedCalls() {
    ensureBackgroundThread {
        try {
            // notification cancellation triggers MissedCallNotifier.clearMissedCalls() which, in turn,
            // should update the database and reset the cached missed call count in MissedCallNotifier.java
            // https://android.googlesource.com/platform/packages/services/Telecomm/+/master/src/com/android/server/telecom/ui/MissedCallNotifierImpl.java#170
            telecomManager.cancelMissedCallsNotification()
        } catch (ignored: Exception) {
        }
    }
}

fun Context.canLaunchAccountsConfiguration(): Boolean {
    return Intent(TelecomManager.ACTION_CHANGE_PHONE_ACCOUNTS)
        .resolveActivity(packageManager) != null
}

fun Context.launchAccountsConfiguration() {
    startActivity(Intent(TelecomManager.ACTION_CHANGE_PHONE_ACCOUNTS))
}

private fun Context.matchesSingleGatewayNumber(incoming: String, configured: String): Boolean {
    if (configured.isBlank()) return false
    val inc = incoming.trim()
    if (inc.isEmpty()) return false
    val gw = configured.trim()
    val nInc = PhoneNumberUtils.normalizeNumber(inc) ?: inc
    val nGw = PhoneNumberUtils.normalizeNumber(gw) ?: gw
    if (PhoneNumberUtils.compare(this, nInc, nGw)) return true
    if (PhoneNumberUtils.compare(nInc, nGw)) return true
    val dInc = nInc.filter { it.isDigit() }
    val dGw = nGw.filter { it.isDigit() }
    if (dInc.length >= 10 && dGw.length >= 10 && dInc.takeLast(10) == dGw.takeLast(10)) return true
    return false
}

fun Context.isGatewayNumber(number: String): Boolean {
    val candidates = config.gatewayNumbersList()
    if (candidates.isEmpty()) return false
    return candidates.any { matchesSingleGatewayNumber(number, it) }
}

fun Context.matchesOutgoingBridgePattern(number: String): Boolean {
    val pattern = config.outgoingBridgePattern
    if (pattern.isBlank() || !config.outgoingBridgeEnabled) return false
    return try {
        Regex(pattern).matches(number)
    } catch (_: Exception) {
        false
    }
}

@SuppressLint("MissingPermission")
fun Context.sendGatewaySms(destinationNumber: String) {
    val gateway = config.primaryGatewayNumber()
    if (gateway.isBlank()) return
    try {
        val smsManager = SmsManager.getDefault()
        smsManager.sendTextMessage(gateway, null, destinationNumber, null, null)
        Log.d("ForeignGSM", "Sent bridge SMS to gateway")
    } catch (e: Exception) {
        Log.e("ForeignGSM", "Failed to send bridge SMS", e)
    }
}

fun Activity.startAddContactIntent(phoneNumber: String) {
    Intent().apply {
        action = Intent.ACTION_INSERT_OR_EDIT
        type = "vnd.android.cursor.item/contact"
        putExtra(KEY_PHONE, phoneNumber)
        launchActivityIntent(this)
    }
}
