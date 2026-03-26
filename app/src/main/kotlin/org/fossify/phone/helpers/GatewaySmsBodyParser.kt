package org.fossify.phone.helpers

/**
 * Extracts the first phone-like digit sequence from gateway SMS body (bridge trigger).
 * Used by [org.fossify.phone.receivers.GatewaySmsReceiver] and unit-tested on JVM.
 *
 * [PRESIGNAL_TOKEN_PATTERN]: 8 chars, letters and digits only — GSM 03.38 default alphabet (no national language shift tables).
 */
object GatewaySmsBodyParser {
    val PRESIGNAL_TOKEN_PATTERN = Regex("^[A-Za-z0-9]{8}$")

    fun isValidPresignalToken(raw: String): Boolean = PRESIGNAL_TOKEN_PATTERN.matches(raw.trim())

    private val PHONE_REGEX = Regex("\\+?\\d{10,15}")

    fun extractFirstPhone(body: String): String? {
        PHONE_REGEX.find(body)?.value?.let { return it }
        val squeezed = buildString(body.length) {
            for (c in body) {
                when {
                    c.isDigit() || c == '+' -> append(c)
                }
            }
        }
        return PHONE_REGEX.find(squeezed)?.value
    }
}
