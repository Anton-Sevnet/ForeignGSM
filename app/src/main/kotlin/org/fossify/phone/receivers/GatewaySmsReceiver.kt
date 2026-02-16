package org.fossify.phone.receivers

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import android.util.Log
import org.fossify.phone.extensions.config
import org.fossify.phone.extensions.isGatewayNumber
import org.fossify.phone.helpers.CallMetadataBridge

class GatewaySmsReceiver : BroadcastReceiver() {
    companion object {
        private const val TAG = "ForeignGSM"
        private val PHONE_REGEX = Regex("\\+?\\d{10,15}")
    }

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return
        if (context.config.gatewayBNumber.isBlank()) return

        val messages = Telephony.Sms.Intents.getMessagesFromIntent(intent)
        for (smsMessage in messages) {
            val sender = smsMessage.originatingAddress ?: continue
            if (!context.isGatewayNumber(sender)) continue

            val body = smsMessage.messageBody ?: continue
            val match = PHONE_REGEX.find(body)
            if (match != null) {
                val realNumber = match.value
                Log.d(TAG, "SMS from gateway: parsed caller ID")
                CallMetadataBridge.storeIncoming(realNumber)
            }
        }
    }
}
