package com.company.internalerp.data.repository_impl

import android.content.Context
import com.company.internalerp.core.auth.TokenStore
import com.company.internalerp.core.db.AppDatabase
import com.company.internalerp.core.network.NetworkFactory
import com.company.internalerp.core.sync.SyncScheduler
import com.company.internalerp.data.api.NotificationMarkReadRequest
import com.company.internalerp.data.api.SalesDoActionRequest
import java.io.File
import java.io.IOException
import java.util.UUID
import java.security.MessageDigest
import com.company.internalerp.data.api.LoginRequest
import com.company.internalerp.data.local.entity.ChatChannelEntity
import com.company.internalerp.data.local.entity.NotificationEntity
import com.company.internalerp.data.local.entity.OutboxActionEntity
import com.company.internalerp.data.local.entity.OutboxMessageEntity
import com.company.internalerp.data.local.entity.SalesDoEntity
import com.company.internalerp.data.local.entity.TaskEntity
import kotlinx.coroutines.flow.Flow
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.Json
import retrofit2.HttpException

class MobileRepository(
    private val context: Context,
    private val baseUrl: String,
    private val fallbackBaseUrls: List<String> = emptyList()
) {
    private val db = AppDatabase.get(context)
    private val tokenStore = TokenStore(context)
    private var activeBaseUrl = baseUrl
    private var api = NetworkFactory.createApi(context, activeBaseUrl)
    private var queueRepo = OfflineQueueRepository(context, activeBaseUrl)

    fun observeSalesDo(): Flow<List<SalesDoEntity>> = db.salesDoDao().observeAll()
    fun observeChannels(): Flow<List<ChatChannelEntity>> = db.chatChannelDao().observeAll()
    fun observeOutboxMessages(): Flow<List<OutboxMessageEntity>> = db.outboxMessageDao().observeAll()
    fun observeOutboxActions(): Flow<List<OutboxActionEntity>> = db.outboxActionDao().observeAll()
    fun observeSyncState(): Flow<List<com.company.internalerp.data.local.entity.SyncStateEntity>> = db.syncStateDao().observeAll()

    fun currentUserHash(): String {
        val source = tokenStore.accessToken().ifBlank { "anonymous" }
        return MessageDigest.getInstance("SHA-256")
            .digest(source.toByteArray())
            .joinToString("") { "%02x".format(it) }
            .take(16)
    }

    suspend fun login(username: String, password: String, deviceId: String): Result<Unit> = runCatching {
        val candidates = (listOf(activeBaseUrl) + fallbackBaseUrls).distinct()
        var lastError: Throwable? = null

        for (candidate in candidates) {
            if (candidate != activeBaseUrl) {
                switchApiBase(candidate)
            }

            try {
                val env = api.login(LoginRequest(username = username, password = password, device_id = deviceId))
                val data = env.data
                val access = data.string("access_token")
                val refresh = data.string("refresh_token")
                if (access.isBlank() || refresh.isBlank()) error("Token response invalid.")
                tokenStore.save(access, refresh)
                return@runCatching
            } catch (t: Throwable) {
                lastError = t
                if (!shouldTryNextBase(t)) {
                    throw t
                }
            }
        }

        throw (lastError ?: IllegalStateException("No reachable mobile API base URL."))
    }

    suspend fun refreshSalesDoCache(): Result<Unit> = runCatching {
        val since = db.salesDoDao().lastCachedAtMillis()
        val env = api.salesDoList(page = 1, limit = 50, since = since)
        val items = env.data.array("items")
        val now = System.currentTimeMillis()
        val mapped = items.mapNotNull { j ->
            val obj = j as? JsonObject ?: return@mapNotNull null
            SalesDoEntity(
                id = obj.int("id"),
                doCode = obj.string("do_code"),
                trackingCode = obj.stringOrNull("tracking_code"),
                status = obj.string("status"),
                customerCode = obj.stringOrNull("customers_code"),
                updatedAt = obj.stringOrNull("updated_at"),
                syncedAtMillis = now,
                serverUpdatedAt = obj.stringOrNull("updated_at"),
                localCachedAtMillis = now,
                needsRefresh = 0
            )
        }
        db.salesDoDao().upsertAll(mapped)
        db.salesDoDao().clearNeedsRefresh()
    }

    suspend fun refreshChatChannelCache(): Result<Unit> = runCatching {
        val env = api.chatChannels()
        val items = env.data.array("items")
        val now = System.currentTimeMillis()
        val mapped = items.mapNotNull { j ->
            val obj = j as? JsonObject ?: return@mapNotNull null
            ChatChannelEntity(
                id = obj.int("id"),
                name = obj.stringOrNull("name"),
                unreadCount = obj.intOrZero("unread_count"),
                mutedUntil = obj.stringOrNull("muted_until"),
                syncedAtMillis = now
            )
        }
        db.chatChannelDao().upsertAll(mapped)
    }

    suspend fun queueChatSend(channelId: Int, text: String) {
        queueRepo.queueChatSend(channelId, text)
    }

    suspend fun queueStockAdjustment(productId: Int, deltaQty: Int, reason: String) {
        queueRepo.queueStockAdjustment(productId, deltaQty, reason)
    }

    suspend fun fetchTasks(): Result<List<String>> = runCatching {
        val env = api.tasksMy()
        env.data.array("items").mapNotNull { j ->
            val obj = j as? JsonObject ?: return@mapNotNull null
            val code = obj.string("do_code")
            val status = obj.string("status")
            "$code | $status"
        }
    }

    suspend fun fetchTaskItems(): Result<List<TaskItem>> = runCatching {
        try {
            val env = api.tasksMy()
            val now = System.currentTimeMillis()
            val mapped = env.data.array("items").mapNotNull { j ->
                val obj = j as? JsonObject ?: return@mapNotNull null
                val id = obj.int("id")
                val doCode = obj.string("do_code")
                if (id <= 0 || doCode.isBlank()) return@mapNotNull null
                TaskEntity(
                    id = id,
                    doCode = doCode,
                    trackingCode = obj.stringOrNull("tracking_code"),
                    status = obj.string("status"),
                    customerCode = obj.stringOrNull("customers_code"),
                    doDate = obj.stringOrNull("do_date"),
                    grandTotal = obj.stringOrNull("grand_total"),
                    syncedAtMillis = now
                )
            }
            db.taskDao().upsertAll(mapped)
            mapped.map { it.toDomain() }
        } catch (_: Throwable) {
            db.taskDao().listAll().map { it.toDomain() }
        }
    }

    suspend fun runTaskAction(task: TaskItem, actionUi: TaskActionUi, note: String): Result<TaskActionDispatch> = runCatching {
        if (actionUi == TaskActionUi.REJECT) {
            error("Reject belum tersedia untuk flow ini.")
        }
        val actionCode = when (actionUi) {
            TaskActionUi.APPROVE, TaskActionUi.COMPLETE -> task.suggestedActionCode
            TaskActionUi.REJECT -> null
        } ?: error("Tidak ada action server untuk status ${task.status}.")
        val cleanNote = note.trim()
        try {
            api.salesDoAction(
                idempotencyKey = UUID.randomUUID().toString(),
                body = SalesDoActionRequest(
                    do_id = task.id,
                    action_code = actionCode,
                    note = cleanNote
                )
            )
            db.salesDoDao().setNeedsRefresh(task.id, 1)
            TaskActionDispatch.SENT
        } catch (t: Throwable) {
            if (shouldQueueTaskAction(t)) {
                queueRepo.queueTaskDoAction(
                    doId = task.id,
                    actionCode = actionCode,
                    note = cleanNote
                )
                TaskActionDispatch.QUEUED
            } else if ((t as? HttpException)?.code() == 409) {
                error("Status data berubah. Silakan muat ulang lalu coba lagi.")
            } else {
                throw t
            }
        }
    }

    suspend fun fetchNotifications(): Result<List<String>> = runCatching {
        val env = api.notificationsList(page = 1, limit = 20)
        env.data.array("items").mapNotNull { j ->
            val obj = j as? JsonObject ?: return@mapNotNull null
            val title = obj.string("title")
            val created = obj.string("created_at")
            if (title.isBlank() && created.isBlank()) null else "$title | $created"
        }
    }

    suspend fun fetchNotificationItems(): Result<List<NotificationItem>> = runCatching {
        try {
            val env = api.notificationsList(page = 1, limit = 30)
            val now = System.currentTimeMillis()
            val mapped = env.data.array("items").mapNotNull { j ->
                val obj = j as? JsonObject ?: return@mapNotNull null
                val id = obj.int("id")
                if (id <= 0) return@mapNotNull null
                NotificationEntity(
                    id = id,
                    notifCode = obj.stringOrNull("notif_code"),
                    title = obj.string("title"),
                    body = obj.stringOrNull("body"),
                    isRead = if (obj.int("is_read") == 1) 1 else 0,
                    createdAt = obj.stringOrNull("created_at"),
                    syncedAtMillis = now
                )
            }
            db.notificationDao().upsertAll(mapped)
            mapped.map { it.toDomain() }
        } catch (_: Throwable) {
            db.notificationDao().listAll().map { it.toDomain() }
        }
    }

    suspend fun markNotificationRead(id: Int): Result<Unit> = runCatching {
        api.notificationsMarkRead(NotificationMarkReadRequest(id = id))
        db.notificationDao().markRead(id)
    }

    suspend fun markAllNotificationsRead(ids: List<Int>): Result<Unit> = runCatching {
        ids.distinct().forEach { id ->
            api.notificationsMarkRead(NotificationMarkReadRequest(id = id))
            db.notificationDao().markRead(id)
        }
    }

    suspend fun cachedNotificationItems(): List<NotificationItem> {
        return db.notificationDao().listAll().map { it.toDomain() }
    }

    suspend fun fetchDashboardSummary(): Result<DashboardSummary> = runCatching {
        val env = api.dashboard()
        val kpi = env.data.obj("kpi")
        val tasks = env.data.obj("tasks")
        val actor = env.data.obj("actor")
        DashboardSummary(
            salesDo = kpi.int("sales_do"),
            po = kpi.int("po"),
            stockAdjustments = kpi.int("stock_adjustments"),
            apUnpaid = kpi.int("ap_unpaid"),
            taskScmDelivery = tasks.int("scm_delivery"),
            taskActFin = tasks.int("act_fin"),
            taskFinPay = tasks.int("fin_pay"),
            taskWqsReady = tasks.int("wqs_ready"),
            department = actor.stringOrNull("department")?.uppercase() ?: ""
        )
    }

    suspend fun retryOutboxAction(localId: Long) {
        db.outboxActionDao().markPending(localId)
        SyncScheduler.enqueueOutboxSync(context, activeBaseUrl)
    }

    fun syncOutboxNow() {
        SyncScheduler.enqueueOutboxSync(context, activeBaseUrl)
    }

    suspend fun resumeFailedSync() {
        db.outboxActionDao().promoteAllFailedToPending()
        SyncScheduler.enqueueOutboxSync(context, activeBaseUrl)
    }

    suspend fun cleanupSentOutbox(days: Int = 7): Int {
        val cutoff = System.currentTimeMillis() - days.coerceAtLeast(1) * 24L * 60L * 60L * 1000L
        return db.outboxActionDao().cleanupSentOlderThan(cutoff)
    }

    suspend fun currentSyncHealth(): SyncHealth {
        val raw = db.syncStateDao().find("sync_health")?.valueJson ?: return SyncHealth()
        return runCatching {
            val obj = Json.parseToJsonElement(raw).jsonObject
            val failureCounterRaw = db.syncStateDao().find("sync_failure_counter")?.valueJson
            val failureCounter = parseFailureCounter(failureCounterRaw)
            SyncHealth(
                lastSyncTs = obj.long("last_sync_ts"),
                pendingCount = obj.int("pending_count"),
                failedCount = obj.int("failed_count"),
                lastErrorCode = obj.stringOrNull("last_error_code"),
                consecutiveFailures = failureCounter.first,
                warningMessage = when {
                    failureCounter.first >= 5 -> "Sinkronisasi bermasalah, mode recovery agresif aktif."
                    failureCounter.first >= 3 -> "Sinkronisasi bermasalah, auto-recovery dijalankan."
                    else -> null
                }
            )
        }.getOrDefault(SyncHealth())
    }

    suspend fun logLocalAnalyticsEvent(actionId: String, actor: String = "user") {
        runCatching {
            val now = System.currentTimeMillis()
            val actorHash = MessageDigest.getInstance("SHA-256")
                .digest(actor.toByteArray())
                .joinToString("") { "%02x".format(it) }
                .take(16)
            db.syncStateDao().upsert(
                com.company.internalerp.data.local.entity.SyncStateEntity(
                    key = "analytics_last_event",
                    valueJson = """{"ts":$now,"event":"DASH_QUICK_ACTION_CLICK","action_id":"$actionId","actor_hash":"$actorHash","request_id":"${UUID.randomUUID()}"}""",
                    updatedAtMillis = now
                )
            )
            val dir = File(context.filesDir, "analytics")
            if (!dir.exists()) dir.mkdirs()
            val file = File(dir, "events.jsonl")
            file.appendText(
                """{"event_id":"${UUID.randomUUID()}","ts":$now,"event":"DASH_QUICK_ACTION_CLICK","action_id":"$actionId","user_hash":"$actorHash","request_id":"${UUID.randomUUID()}"}""" + "\n"
            )
        }
    }

    suspend fun readLocalAnalyticsTail(limit: Int = 20): List<String> {
        val file = File(context.filesDir, "analytics/events.jsonl")
        if (!file.exists()) return emptyList()
        return file.readLines().takeLast(limit.coerceAtLeast(1))
    }

    private fun NotificationEntity.toDomain(): NotificationItem {
        return NotificationItem(
            id = id,
            notifCode = notifCode,
            title = title,
            body = body,
            isRead = isRead == 1,
            createdAt = createdAt
        )
    }

    private fun TaskEntity.toDomain(): TaskItem {
        return TaskItem(
            id = id,
            doCode = doCode,
            trackingCode = trackingCode,
            status = status,
            customerCode = customerCode,
            doDate = doDate,
            grandTotal = grandTotal,
            suggestedActionCode = suggestedActionForStatus(status)
        )
    }

    fun logout() {
        tokenStore.clear()
    }

    private fun switchApiBase(newBaseUrl: String) {
        activeBaseUrl = newBaseUrl
        api = NetworkFactory.createApi(context, newBaseUrl)
        queueRepo = OfflineQueueRepository(context, newBaseUrl)
    }

    private fun shouldTryNextBase(t: Throwable): Boolean {
        return when (t) {
            is IOException -> true
            is HttpException -> t.code() == 404
            else -> false
        }
    }

    private fun shouldQueueTaskAction(t: Throwable): Boolean {
        return when (t) {
            is IOException -> true
            is HttpException -> t.code() in 500..599
            else -> false
        }
    }
}

data class TaskItem(
    val id: Int,
    val doCode: String,
    val trackingCode: String?,
    val status: String,
    val customerCode: String?,
    val doDate: String?,
    val grandTotal: String?,
    val suggestedActionCode: String?
)

enum class TaskActionUi {
    APPROVE,
    REJECT,
    COMPLETE
}

enum class TaskActionDispatch {
    SENT,
    QUEUED
}

data class NotificationItem(
    val id: Int,
    val notifCode: String?,
    val title: String,
    val body: String?,
    val isRead: Boolean,
    val createdAt: String?
)

private fun suggestedActionForStatus(status: String): String? {
    return when (status.lowercase()) {
        "crm_to_wqs", "sent_wqs" -> "wqs_start"
        "wqs_processing" -> "wqs_ready"
        "ready_scm" -> "scm_start"
        "on_delivery" -> "scm_delivered"
        "delivered" -> "act_send_fin"
        "wait_payment" -> "fin_paid"
        else -> null
    }
}

private fun JsonObject.string(key: String): String = this[key]?.jsonPrimitive?.content ?: ""
private fun JsonObject.stringOrNull(key: String): String? =
    (this[key] as? JsonPrimitive)?.contentOrNull
private fun JsonObject.int(key: String): Int = this[key]?.jsonPrimitive?.content?.toIntOrNull() ?: 0
private fun JsonObject.long(key: String): Long = this[key]?.jsonPrimitive?.content?.toLongOrNull() ?: 0L
private fun JsonObject.intOrZero(key: String): Int = int(key)
private fun JsonObject.array(key: String): JsonArray = this[key]?.jsonArray ?: JsonArray(emptyList())
private fun JsonObject.obj(key: String): JsonObject = this[key] as? JsonObject ?: JsonObject(emptyMap())

data class DashboardSummary(
    val salesDo: Int,
    val po: Int,
    val stockAdjustments: Int,
    val apUnpaid: Int,
    val taskScmDelivery: Int,
    val taskActFin: Int,
    val taskFinPay: Int,
    val taskWqsReady: Int,
    val department: String
)

data class SyncHealth(
    val lastSyncTs: Long = 0L,
    val pendingCount: Int = 0,
    val failedCount: Int = 0,
    val lastErrorCode: String? = null,
    val consecutiveFailures: Int = 0,
    val warningMessage: String? = null
)

private fun parseFailureCounter(raw: String?): Pair<Int, String?> {
    if (raw.isNullOrBlank()) return 0 to null
    return runCatching {
        val obj = Json.parseToJsonElement(raw).jsonObject
        val count = obj.int("consecutive_failures")
        val code = obj.stringOrNull("last_failure_code")
        count to code
    }.getOrDefault(0 to null)
}
