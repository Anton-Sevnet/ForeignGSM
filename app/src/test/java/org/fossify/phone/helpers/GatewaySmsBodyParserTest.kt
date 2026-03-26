package org.fossify.phone.helpers

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class GatewaySmsBodyParserTest {

    @Test
    fun extractFirstPhone_plainDigits() {
        assertEquals(
            "79001234567",
            GatewaySmsBodyParser.extractFirstPhone("Call from 79001234567 please")
        )
    }

    @Test
    fun extractFirstPhone_withPlus() {
        assertEquals(
            "+79001234567",
            GatewaySmsBodyParser.extractFirstPhone("From +79001234567")
        )
    }

    @Test
    fun extractFirstPhone_prefersFirstMatch() {
        assertEquals(
            "79001111111",
            GatewaySmsBodyParser.extractFirstPhone("79001111111 then 79002222222")
        )
    }

    @Test
    fun extractFirstPhone_noMatch() {
        assertNull(GatewaySmsBodyParser.extractFirstPhone("no digits here"))
    }

    @Test
    fun extractFirstPhone_tooShortIgnored() {
        assertNull(GatewaySmsBodyParser.extractFirstPhone("12345"))
    }

    @Test
    fun extractFirstPhone_presignalTemplate() {
        assertEquals(
            "79787271759",
            GatewaySmsBodyParser.extractFirstPhone("From:79787271759 bridge")
        )
    }

    @Test
    fun extractFirstPhone_digitsBrokenBySpaces() {
        assertEquals(
            "79219729637",
            GatewaySmsBodyParser.extractFirstPhone("From: 7 921 972 96 37 bridge")
        )
    }

    @Test
    fun isValidPresignalToken_eightAlnum() {
        assertTrue(GatewaySmsBodyParser.isValidPresignalToken("F3G8M2KX"))
        assertTrue(GatewaySmsBodyParser.isValidPresignalToken("abcdef12"))
    }

    @Test
    fun isValidPresignalToken_rejectsWrongLength() {
        assertFalse(GatewaySmsBodyParser.isValidPresignalToken("F3G8M2K"))
        assertFalse(GatewaySmsBodyParser.isValidPresignalToken("F3G8M2KXX"))
    }

    @Test
    fun isValidPresignalToken_rejectsSpecialChars() {
        assertFalse(GatewaySmsBodyParser.isValidPresignalToken("F3G8M-KX"))
    }

    @Test
    fun extractFirstPhone_withTrailingPresignalToken() {
        assertEquals(
            "+79001234567",
            GatewaySmsBodyParser.extractFirstPhone("From:+79001234567 bridge F3G8M2KX")
        )
    }
}
