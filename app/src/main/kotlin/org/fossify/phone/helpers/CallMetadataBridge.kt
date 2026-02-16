package org.fossify.phone.helpers

import android.util.Log

object CallMetadataBridge {
    private const val TAG = "ForeignGSM"
    private const val TTL_MS = 10_000L

    @Volatile
    private var realCallerNumber: String? = null

    @Volatile
    private var incomingTimestamp: Long = 0L

    @Volatile
    private var realDestinationNumber: String? = null

    @Synchronized
    fun storeIncoming(number: String) {
        realCallerNumber = number
        incomingTimestamp = System.currentTimeMillis()
        Log.d(TAG, "Bridge: stored incoming caller data (TTL=${TTL_MS}ms)")
    }

    @Synchronized
    fun fetchIncoming(): String? {
        val data = realCallerNumber
        if (data != null && System.currentTimeMillis() - incomingTimestamp <= TTL_MS) {
            realCallerNumber = null
            Log.d(TAG, "Bridge: fetched incoming caller data")
            return data
        }
        if (data != null) {
            Log.d(TAG, "Bridge: incoming data expired")
        }
        realCallerNumber = null
        return null
    }

    @Synchronized
    fun storeOutgoing(number: String) {
        realDestinationNumber = number
        Log.d(TAG, "Bridge: stored outgoing destination")
    }

    @Synchronized
    fun fetchOutgoing(): String? {
        val data = realDestinationNumber
        realDestinationNumber = null
        return data
    }

    @Synchronized
    fun clear() {
        realCallerNumber = null
        incomingTimestamp = 0L
        realDestinationNumber = null
    }
}
