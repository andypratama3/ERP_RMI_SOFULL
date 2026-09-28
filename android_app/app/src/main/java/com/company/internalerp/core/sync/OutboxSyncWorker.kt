package com.company.internalerp.core.sync

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.company.internalerp.core.db.AppDatabase
import com.company.internalerp.core.network.NetworkFactory
import com.company.internalerp.data.api.ChatSendRequest
import com.company.internalerp.data.api.SalesDoActionRequest
import com.company.internalerp.data.api.StockAdjustmentCreateRequest
import com.company.internalerp.data.local.entity.SyncStateEntity
import java.io.IOException
import java.util.UUID
import kotlin.random.Random
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import retrofit2.HttpException
import timber.log.Timber

class OutboxSyncWorker(
    appContext: Context,
    params: WorkerParameters
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val baseUrl = inputData.getString(KEY_BASE_URL) ?: return Result.retry()
        val db = AppDatabase.get(applicationContext)
        val api = NetworkFactory.createApi(applicationContext, baseUrl)
        val now = System.currentTimeMillis()
        val workerRunId = UUID.randomUUID().toString()
        val priorCounter = parseFailureCounter(db.syncStateDao().find(FAILURE_COUNTER_KEY)?.valueJson)
        val elevatedBackoff = priorCounter.consecutiveFailures >= 5
        var lastErrorCode: String? = null

        try {
            val recoveredCount = db.outboxActionDao().recoverExpiredInFlight(
                nowMillis = now,
                workerRunId = workerRunId
            )
            if (recoveredCount > 0) {
                lastErrorCode = "RECOVERED_STALE_INFLIGHT"
            }

            db.outboxMessageDao().pending().forEach { row ->
                runCatching {
                    api.chatSend(
                        idempotencyKey = row.idempotencyKey,
                        body = ChatSendRequest(channel_id = row.channelId, message_text = row.messageText)
                    )
                }.onSuccess {
                    db.outboxMessageDao().mark(row.localId, "SENT", null)
                }.onFailure { e ->
                    val code = when {
                        (e as? HttpException)?.code() == 409 -> "ERR_CONFLICT_STATE_CHANGED"
                        e.message?.contains("ERR_CONFLICT_STATE_CHANGED") == true -> "ERR_CONFLICT_STATE_CHANGED"
                        else -> "ERR_INTERNAL"
                    }
                    db.outboxMessageDao().mark(row.localId, "FAILED", code)
                }
            }

            db.outboxActionDao().pending(now).forEach { row ->
                db.outboxActionDao().markInFlight(
                    localId = row.localId,
                    nowMillis = now,
                    leaseUntilMillis = now + LEASE_TTL_MILLIS,
                    workerRunId = workerRunId
                )
                if (row.actionType == ACTION_STOCK_ADJUSTMENT_CREATE) {
                    runCatching {
                        val p = Json.decodeFromString(StockAdjustmentPayload.serializer(), row.payloadJson)
                        api.stockAdjustmentCreate(
                            idempotencyKey = row.idempotencyKey,
                            body = StockAdjustmentCreateRequest(
                                product_id = p.productId,
                                delta_qty = p.deltaQty,
                                reason = p.reason
                            )
                        )
                    }.onSuccess {
                        db.outboxActionDao().markSent(row.localId, workerRunId = workerRunId)
                    }.onFailure { e ->
                        val code = mapErrorCode(e)
                        lastErrorCode = code
                        val attempts = row.attempts + 1
                        val httpStatus = (e as? HttpException)?.code()
                        val masked = maskErrorMessage(e.message, code)
                        db.outboxActionDao().markFailed(
                            localId = row.localId,
                            errorCode = code,
                            errorMessageMasked = masked,
                            httpStatus = httpStatus,
                            attempts = attempts,
                            nextRetryAtMillis = now + SyncBackoffPolicy.computeBackoffMillis(
                                attempts = attempts,
                                jitterMillis = Random.nextLong(0L, 3001L),
                                elevatedCap = elevatedBackoff
                            ),
                            workerRunId = workerRunId
                        )
                    }
                }
                if (row.actionType == ACTION_TASK_DO_ACTION) {
                    runCatching {
                        val p = Json.decodeFromString(TaskDoActionPayload.serializer(), row.payloadJson)
                        api.salesDoAction(
                            idempotencyKey = row.idempotencyKey,
                            body = SalesDoActionRequest(
                                do_id = p.doId,
                                action_code = p.actionCode,
                                note = p.note
                            )
                        )
                    }.onSuccess {
                        db.outboxActionDao().markSent(row.localId, workerRunId = workerRunId)
                    }.onFailure { e ->
                        val code = mapErrorCode(e)
                        lastErrorCode = code
                        val attempts = row.attempts + 1
                        val httpStatus = (e as? HttpException)?.code()
                        val masked = maskErrorMessage(e.message, code)
                        db.outboxActionDao().markFailed(
                            localId = row.localId,
                            errorCode = code,
                            errorMessageMasked = masked,
                            httpStatus = httpStatus,
                            attempts = attempts,
                            nextRetryAtMillis = now + SyncBackoffPolicy.computeBackoffMillis(
                                attempts = attempts,
                                jitterMillis = Random.nextLong(0L, 3001L),
                                elevatedCap = elevatedBackoff
                            ),
                            workerRunId = workerRunId
                        )
                    }
                }
            }

            val counters = updateFailureCounter(
                db = db,
                now = now,
                errorCode = lastErrorCode
            )
            if (counters.consecutiveFailures >= 3) {
                db.outboxActionDao().recoverExpiredInFlight(
                    nowMillis = now,
                    workerRunId = workerRunId,
                    errorCode = "RECOVERED_AUTO_SWEEP",
                    errorMessageMasked = "Auto-recovery sweep triggered"
                )
                db.outboxActionDao().promoteAllFailedToPending()
            }

            db.syncStateDao().upsert(
                SyncStateEntity(
                    key = "sync_health",
                    valueJson = """
                        {"last_sync_ts":$now,"pending_count":${db.outboxActionDao().countPending()},"failed_count":${db.outboxActionDao().countFailed()},"last_error_code":"${lastErrorCode ?: ""}","worker_run_id":"$workerRunId","consecutive_failures":${counters.consecutiveFailures}}
                    """.trimIndent(),
                    updatedAtMillis = now
                )
            )
            return Result.success()
        } catch (e: Throwable) {
            Timber.w(e, "Outbox sync worker failed")
            val code = mapErrorCode(e)
            val counters = updateFailureCounter(db = db, now = now, errorCode = code)
            db.syncStateDao().upsert(
                SyncStateEntity(
                    key = "sync_health",
                    valueJson = """{"last_sync_ts":$now,"pending_count":${db.outboxActionDao().countPending()},"failed_count":${db.outboxActionDao().countFailed()},"last_error_code":"$code","worker_run_id":"$workerRunId","consecutive_failures":${counters.consecutiveFailures}}""",
                    updatedAtMillis = now
                )
            )
            return Result.retry()
        }
    }

    companion object {
        const val KEY_BASE_URL = "base_url"
        const val ACTION_STOCK_ADJUSTMENT_CREATE = "STOCK_ADJUSTMENT_CREATE"
        const val ACTION_TASK_DO_ACTION = "TASK_DO_ACTION"
        private const val LEASE_TTL_MILLIS = 2 * 60 * 1000L
        private const val FAILURE_COUNTER_KEY = "sync_failure_counter"
    }

    private suspend fun updateFailureCounter(
        db: AppDatabase,
        now: Long,
        errorCode: String?
    ): FailureCounter {
        val raw = db.syncStateDao().find(FAILURE_COUNTER_KEY)?.valueJson
        val prev = parseFailureCounter(raw)
        val withinWindow = (now - prev.lastFailureTs) <= 10 * 60 * 1000L
        val next = if (errorCode.isNullOrBlank()) {
            FailureCounter()
        } else {
            val consecutive = if (withinWindow) prev.consecutiveFailures + 1 else 1
            FailureCounter(
                consecutiveFailures = consecutive,
                lastFailureTs = now,
                lastFailureCode = errorCode
            )
        }
        val payload = buildJsonObject {
            put("consecutive_failures", JsonPrimitive(next.consecutiveFailures))
            put("last_failure_ts", JsonPrimitive(next.lastFailureTs))
            put("last_failure_code", JsonPrimitive(next.lastFailureCode ?: ""))
        }.toString()
        db.syncStateDao().upsert(
            SyncStateEntity(
                key = FAILURE_COUNTER_KEY,
                valueJson = payload,
                updatedAtMillis = now
            )
        )
        return next
    }

    private fun parseFailureCounter(raw: String?): FailureCounter {
        if (raw.isNullOrBlank()) return FailureCounter()
        return runCatching {
            val obj = Json.parseToJsonElement(raw) as? JsonObject ?: return FailureCounter()
            FailureCounter(
                consecutiveFailures = obj["consecutive_failures"]?.toString()?.trim('"')?.toIntOrNull() ?: 0,
                lastFailureTs = obj["last_failure_ts"]?.toString()?.trim('"')?.toLongOrNull() ?: 0L,
                lastFailureCode = obj["last_failure_code"]?.toString()?.trim('"').orEmpty().ifBlank { null }
            )
        }.getOrDefault(FailureCounter())
    }

    private fun mapErrorCode(e: Throwable): String {
        val http = e as? HttpException
        return when {
            http?.code() == 409 -> "ERR_CONFLICT_STATE_CHANGED"
            http?.code() in listOf(502, 503, 504) -> "ERR_NETWORK_FLAPPING"
            e is IOException -> "ERR_NETWORK_FLAPPING"
            e.message?.contains("timeout", ignoreCase = true) == true -> "ERR_NETWORK_FLAPPING"
            e.message?.contains("ERR_CONFLICT_STATE_CHANGED") == true -> "ERR_CONFLICT_STATE_CHANGED"
            else -> "ERR_INTERNAL"
        }
    }

    private fun maskErrorMessage(message: String?, code: String): String {
        if (message.isNullOrBlank()) return code
        return message
            .replace(Regex("(?i)bearer\\s+[a-z0-9\\-._~+/]+=*"), "Bearer [REDACTED]")
            .replace(Regex("(?i)(password|token|cookie|session)[=:\\s][^\\s,;]+"), "$1=[REDACTED]")
            .take(200)
    }
}

private data class FailureCounter(
    val consecutiveFailures: Int = 0,
    val lastFailureTs: Long = 0L,
    val lastFailureCode: String? = null
)
