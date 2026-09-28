package com.company.internalerp.core.network

object Redaction {
    fun redactSensitive(input: String): String {
        return input
            .replace(Regex("(?i)(authorization: ).*"), "$1[REDACTED]")
            .replace(Regex("(?i)(refresh_token|access_token|token|password|cookie|session)[=:\\\"\\s]+[^\\s\\\",;]+"), "$1=[REDACTED]")
            .replace(Regex("(?i)([?&](token|password|session|refresh_token)=)[^&\\s]+"), "$1[REDACTED]")
    }
}
