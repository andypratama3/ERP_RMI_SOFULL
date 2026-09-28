package com.company.internalerp.core.sync

import kotlin.math.min

object SyncBackoffPolicy {
    private const val BASE_MILLIS = 10_000L
    private const val MAX_MILLIS = 15 * 60 * 1000L
    private const val MAX_MILLIS_ELEVATED = 30 * 60 * 1000L

    fun computeBackoffMillis(attempts: Int, jitterMillis: Long, elevatedCap: Boolean = false): Long {
        val exp = min(14, attempts.coerceAtLeast(1))
        val boundedJitter = jitterMillis.coerceIn(0L, 3000L)
        val max = if (elevatedCap) MAX_MILLIS_ELEVATED else MAX_MILLIS
        return min(max, BASE_MILLIS * (1L shl exp)) + boundedJitter
    }
}
