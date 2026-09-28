package com.company.internalerp

import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.company.internalerp.core.db.AppDatabase
import com.company.internalerp.data.repository_impl.OfflineQueueRepository
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class TaskOutboxFlowInstrumentedTest {

    private lateinit var db: AppDatabase
    private lateinit var queueRepo: OfflineQueueRepository

    @Before
    fun setup() {
        val context = ApplicationProvider.getApplicationContext<android.content.Context>()
        db = AppDatabase.get(context)
        db.clearAllTables()
        queueRepo = OfflineQueueRepository(
            context = context,
            baseUrl = "http://127.0.0.1:65535/api/v1/mobile/"
        )
    }

    @Test
    fun queuedTaskAction_canTransitionToSent_whenSyncSucceeds() = runBlocking {
        queueRepo.queueTaskDoAction(
            doId = 777,
            actionCode = "scm_start",
            note = "queued from instrumentation flow"
        )

        val before = db.outboxActionDao().pending(System.currentTimeMillis())
        assertEquals(1, before.size)
        assertEquals("TASK_DO_ACTION", before[0].actionType)
        assertEquals("PENDING", before[0].status)
        assertTrue(before[0].dedupKey.isNotBlank())

        // Simulate successful sync worker submission.
        db.outboxActionDao().markSent(before[0].localId)

        val rows = db.outboxActionDao().listAllSnapshot()
        assertTrue(rows.any { it.actionType == "TASK_DO_ACTION" && it.status == "SENT" })
        val stillPending = db.outboxActionDao().pending(System.currentTimeMillis())
        assertEquals(0, stillPending.size)
    }
}
