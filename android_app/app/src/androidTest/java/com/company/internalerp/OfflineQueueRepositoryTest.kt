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
class OfflineQueueRepositoryTest {

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
    fun queueChatSend_persistsPendingMessage() = runBlocking {
        queueRepo.queueChatSend(channelId = 1, messageText = "offline test message")

        val pending = db.outboxMessageDao().pending()
        assertEquals(1, pending.size)
        assertEquals("PENDING", pending[0].status)
        assertEquals(1, pending[0].channelId)
        assertTrue(pending[0].idempotencyKey.isNotBlank())
    }

    @Test
    fun queueStockAdjustment_persistsPendingAction() = runBlocking {
        queueRepo.queueStockAdjustment(
            productId = 10,
            deltaQty = -2,
            reason = "offline stock adjustment test"
        )

        val pending = db.outboxActionDao().pending(System.currentTimeMillis())
        assertEquals(1, pending.size)
        assertEquals("PENDING", pending[0].status)
        assertEquals("STOCK_ADJUSTMENT_CREATE", pending[0].actionType)
        assertTrue(pending[0].payloadJson.contains("\"productId\":10"))
        assertTrue(pending[0].idempotencyKey.isNotBlank())
        assertTrue(pending[0].dedupKey.isNotBlank())
    }

    @Test
    fun queueTaskDoAction_persistsPendingAction() = runBlocking {
        queueRepo.queueTaskDoAction(
            doId = 99,
            actionCode = "scm_start",
            note = "offline queued action"
        )

        val pending = db.outboxActionDao().pending(System.currentTimeMillis())
        assertEquals(1, pending.size)
        assertEquals("TASK_DO_ACTION", pending[0].actionType)
        assertEquals("PENDING", pending[0].status)
        assertTrue(pending[0].payloadJson.contains("\"doId\":99"))
        assertTrue(pending[0].dedupKey.startsWith("TASK_DO_ACTION:"))
    }
}
