package com.company.internalerp.data.repository_impl

import android.content.Context
import com.company.internalerp.core.db.AppDatabase
import com.company.internalerp.core.sync.OutboxDedupKey
import com.company.internalerp.core.sync.OutboxSyncWorker
import com.company.internalerp.core.sync.StockAdjustmentPayload
import com.company.internalerp.core.sync.SyncScheduler
import com.company.internalerp.core.sync.TaskDoActionPayload
import com.company.internalerp.data.local.entity.OutboxActionEntity
import com.company.internalerp.data.local.entity.OutboxMessageEntity
import java.util.UUID
import kotlinx.serialization.json.Json

class OfflineQueueRepository(
    private val context: Context,
    private val baseUrl: String
) {
    private val db = AppDatabase.get(context)

    suspend fun queueChatSend(channelId: Int, messageText: String) {
        db.outboxMessageDao().insert(
            OutboxMessageEntity(
                channelId = channelId,
                messageText = messageText,
                idempotencyKey = UUID.randomUUID().toString(),
                status = "PENDING",
                lastErrorCode = null,
                createdAtMillis = System.currentTimeMillis()
            )
        )
        SyncScheduler.enqueueOutboxSync(context, baseUrl)
    }

    suspend fun queueStockAdjustment(productId: Int, deltaQty: Int, reason: String) {
        val payload = StockAdjustmentPayload(productId = productId, deltaQty = deltaQty, reason = reason)
        val idemKey = UUID.randomUUID().toString()
        db.outboxActionDao().insert(
            OutboxActionEntity(
                actionType = OutboxSyncWorker.ACTION_STOCK_ADJUSTMENT_CREATE,
                dedupKey = OutboxDedupKey.of(OutboxSyncWorker.ACTION_STOCK_ADJUSTMENT_CREATE, idemKey),
                payloadJson = Json.encodeToString(StockAdjustmentPayload.serializer(), payload),
                idempotencyKey = idemKey,
                status = "PENDING",
                lastErrorCode = null,
                createdAtMillis = System.currentTimeMillis()
            )
        )
        SyncScheduler.enqueueOutboxSync(context, baseUrl)
    }

    suspend fun queueTaskDoAction(doId: Int, actionCode: String, note: String) {
        val payload = TaskDoActionPayload(doId = doId, actionCode = actionCode, note = note)
        val idemKey = UUID.randomUUID().toString()
        db.outboxActionDao().insert(
            OutboxActionEntity(
                actionType = OutboxSyncWorker.ACTION_TASK_DO_ACTION,
                dedupKey = OutboxDedupKey.of(OutboxSyncWorker.ACTION_TASK_DO_ACTION, idemKey),
                payloadJson = Json.encodeToString(TaskDoActionPayload.serializer(), payload),
                idempotencyKey = idemKey,
                status = "PENDING",
                lastErrorCode = null,
                createdAtMillis = System.currentTimeMillis()
            )
        )
        SyncScheduler.enqueueOutboxSync(context, baseUrl)
    }
}
