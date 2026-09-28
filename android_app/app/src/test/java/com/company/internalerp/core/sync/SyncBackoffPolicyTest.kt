package com.company.internalerp.core.sync

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class SyncBackoffPolicyTest {

    @Test
    fun computeBackoffMillis_growsExponentially() {
        val t1 = SyncBackoffPolicy.computeBackoffMillis(attempts = 1, jitterMillis = 0)
        val t2 = SyncBackoffPolicy.computeBackoffMillis(attempts = 2, jitterMillis = 0)
        val t3 = SyncBackoffPolicy.computeBackoffMillis(attempts = 3, jitterMillis = 0)
        assertTrue(t2 > t1)
        assertTrue(t3 > t2)
    }

    @Test
    fun computeBackoffMillis_appliesCap() {
        val t = SyncBackoffPolicy.computeBackoffMillis(attempts = 100, jitterMillis = 3000)
        assertEquals(15 * 60 * 1000L + 3000L, t)
    }
}
