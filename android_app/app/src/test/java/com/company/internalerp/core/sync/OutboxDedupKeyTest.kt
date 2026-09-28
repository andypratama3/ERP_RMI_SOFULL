package com.company.internalerp.core.sync

import org.junit.Assert.assertEquals
import org.junit.Test

class OutboxDedupKeyTest {

    @Test
    fun dedupKey_usesTypeAndIdempotencyKey() {
        val key = OutboxDedupKey.of("TASK_DO_ACTION", "abc-123")
        assertEquals("TASK_DO_ACTION:abc-123", key)
    }
}
