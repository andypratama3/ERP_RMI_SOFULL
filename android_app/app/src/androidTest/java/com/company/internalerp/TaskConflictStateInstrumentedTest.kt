package com.company.internalerp

import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.company.internalerp.core.db.AppDatabase
import com.company.internalerp.core.sync.OutboxSyncWorker
import com.company.internalerp.data.local.entity.OutboxActionEntity
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class TaskConflictStateInstrumentedTest {
    private lateinit var db: AppDatabase

    @Before
    fun setup() {
        val context = ApplicationProvider.getApplicationContext<android.content.Context>()
        db = AppDatabase.get(context)
        db.clearAllTables()
    }

    @Test
    fun outboxConflictState_shouldStoreFailedCodeAndRemainRecoverable() = runBlocking {
        val now = System.currentTimeMillis()
        val localId = db.outboxActionDao().insert(
            OutboxActionEntity(
                actionType = OutboxSyncWorker.ACTION_TASK_DO_ACTION,
                dedupKey = "TASK_DO_ACTION:test-conflict",
                payloadJson = """{"doId":1,"actionCode":"scm_start","note":"x"}""",
                idempotencyKey = "test-conflict",
                status = "PENDING",
                lastErrorCode = null,
                createdAtMillis = now
            )
        )
        assertTrue(localId > 0L)
        val row = db.outboxActionDao().listAllSnapshot().first()
        db.outboxActionDao().markFailed(
            localId = row.localId,
            errorCode = "ERR_CONFLICT_STATE_CHANGED",
            errorMessageMasked = "state changed, refresh required",
            httpStatus = 409,
            attempts = 1,
            nextRetryAtMillis = now + 1000L,
            workerRunId = "instrumented-conflict"
        )

        val failed = db.outboxActionDao().failed().first()
        assertEquals("FAILED", failed.status)
        assertEquals("ERR_CONFLICT_STATE_CHANGED", failed.lastErrorCode)
        assertEquals(409, failed.lastHttpStatus)
        assertTrue((failed.lastErrorMessageMasked ?: "").contains("refresh"))
    }
}
