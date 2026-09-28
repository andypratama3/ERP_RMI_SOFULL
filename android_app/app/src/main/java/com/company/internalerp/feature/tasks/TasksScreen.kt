package com.company.internalerp.feature.tasks

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.company.internalerp.core.ui.settings.UiSettingsStore
import com.company.internalerp.core.sync.OutboxSyncWorker
import com.company.internalerp.core.sync.TaskDoActionPayload
import com.company.internalerp.data.local.entity.OutboxActionEntity
import com.company.internalerp.data.repository_impl.MobileRepository
import com.company.internalerp.data.repository_impl.TaskActionUi
import com.company.internalerp.data.repository_impl.TaskActionDispatch
import com.company.internalerp.data.repository_impl.TaskItem
import com.company.internalerp.design_system.components.AppCard
import com.company.internalerp.design_system.components.StatusBadge
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TasksScreen(repository: MobileRepository) {
    val context = LocalContext.current
    val settingsStore = remember { UiSettingsStore(context) }
    val userHash = remember { repository.currentUserHash() }
    val scope = rememberCoroutineScope()
    val outboxActions by repository.observeOutboxActions().collectAsState(initial = emptyList())
    var rows by remember { mutableStateOf(emptyList<TaskItem>()) }
    var isLoading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var toastMessage by remember { mutableStateOf<String?>(null) }
    var selectedTask by remember { mutableStateOf<TaskItem?>(null) }
    var detailTask by remember { mutableStateOf<TaskItem?>(null) }
    var selectedAction by remember { mutableStateOf<TaskActionUi?>(null) }
    var actionNote by remember { mutableStateOf("") }
    var searchInput by remember { mutableStateOf("") }
    var searchQuery by remember { mutableStateOf("") }
    var filter by remember { mutableStateOf(TaskFilter.ALL) }
    var refreshHint by remember { mutableStateOf<String?>(null) }
    var searchDebounceJob by remember { mutableStateOf<Job?>(null) }

    fun refresh() {
        scope.launch {
            isLoading = true
            repository.fetchTaskItems()
                .onSuccess {
                    rows = it
                    error = null
                }
                .onFailure {
                    error = it.message ?: "Failed."
                }
            isLoading = false
        }
    }

    LaunchedEffect(Unit) {
        settingsStore.tasksFilter(userHash).collect { saved ->
            filter = TaskFilter.from(saved)
        }
    }
    LaunchedEffect(Unit) {
        settingsStore.tasksSearch(userHash).collect { saved ->
            searchInput = saved
            searchQuery = saved
        }
    }

    LaunchedEffect(Unit) {
        refresh()
    }

    val taskOutboxRows by remember(outboxActions) {
        derivedStateOf {
            outboxActions.filter { it.actionType == OutboxSyncWorker.ACTION_TASK_DO_ACTION }
        }
    }
    val summary by remember(taskOutboxRows) {
        derivedStateOf {
            OutboxSummary(
                pending = taskOutboxRows.count { it.status == "PENDING" || it.status == "IN_FLIGHT" },
                sent = taskOutboxRows.count { it.status == "SENT" },
                failed = taskOutboxRows.count { it.status == "FAILED" }
            )
        }
    }
    val filteredRows by remember(rows, searchQuery, filter, taskOutboxRows) {
        derivedStateOf {
            rows.filter { row ->
                val q = searchQuery.trim().lowercase()
                val matchesQuery = if (q.isBlank()) true else {
                    row.doCode.lowercase().contains(q) ||
                        (row.customerCode ?: "").lowercase().contains(q) ||
                        row.status.lowercase().contains(q)
                }
                val latestOutboxStatus = latestOutboxStatusForTask(taskOutboxRows, row.id)
                val matchesFilter = when (filter) {
                    TaskFilter.ALL -> true
                    TaskFilter.PENDING -> latestOutboxStatus == "PENDING" || latestOutboxStatus == "IN_FLIGHT"
                    TaskFilter.FAILED -> latestOutboxStatus == "FAILED"
                    TaskFilter.SENT -> latestOutboxStatus == "SENT"
                }
                matchesQuery && matchesFilter
            }
        }
    }

    Column(
        modifier = Modifier.padding(SpacingTokens.Lg),
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text(
            text = "Tasks",
            style = MaterialTheme.typography.titleLarge,
            color = ColorTokens.TextPrimary
        )
        Text(
            text = "Action items untuk DO workflow (ACT/FIN/SCM/WQS)",
            style = MaterialTheme.typography.bodyMedium,
            color = ColorTokens.TextSecondary
        )

        AppCard {
            Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                Text("Antrian aksi task", color = ColorTokens.TextSecondary)
                Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                    StatusBadge("PENDING ${summary.pending}")
                    StatusBadge("SENT ${summary.sent}")
                    StatusBadge("FAILED ${summary.failed}")
                }
                OutlinedButton(
                    onClick = {
                        repository.syncOutboxNow()
                        toastMessage = "Paksa sinkronisasi dijalankan."
                    },
                    modifier = Modifier.fillMaxWidth().semantics { contentDescription = "sync_now_button" }
                ) { Text("Paksa sinkronisasi") }
            }
        }

        OutlinedTextField(
            value = searchInput,
            onValueChange = {
                searchInput = it
                searchDebounceJob?.cancel()
                searchDebounceJob = scope.launch {
                    delay(300)
                    searchQuery = searchInput
                    settingsStore.setTasksSearch(userHash, searchInput)
                }
            },
            modifier = Modifier.fillMaxWidth().semantics { contentDescription = "tasks_search" },
            label = { Text("Cari task") },
            placeholder = { Text("Cari do_code / customer...") }
        )

        Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            TaskFilter.values().forEach { f ->
                if (f == filter) {
                    Button(
                        onClick = {
                            filter = f
                            scope.launch { settingsStore.setTasksFilter(userHash, f.name) }
                        },
                        modifier = Modifier.semantics { contentDescription = "tasks_filter_${f.name.lowercase()}" }
                    ) { Text(f.label) }
                } else {
                    OutlinedButton(
                        onClick = {
                            filter = f
                            scope.launch { settingsStore.setTasksFilter(userHash, f.name) }
                        },
                        modifier = Modifier.semantics { contentDescription = "tasks_filter_${f.name.lowercase()}" }
                    ) { Text(f.label) }
                }
            }
        }

        Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            Button(onClick = {
                refreshHint = "Memuat pembaruan..."
                refresh()
            }) { Text(if (isLoading) "Memuat..." else "Muat ulang") }
            OutlinedButton(onClick = {
                searchInput = ""
                searchQuery = ""
                filter = TaskFilter.ALL
                scope.launch {
                    settingsStore.setTasksSearch(userHash, "")
                    settingsStore.setTasksFilter(userHash, TaskFilter.ALL.name)
                }
            }) { Text("Reset filter") }
        }

        refreshHint?.let {
            AppCard { Text(it, color = ColorTokens.TextMuted) }
        }
        toastMessage?.let { msg ->
            AppCard {
                Text(msg, color = ColorTokens.TextSecondary)
            }
        }

        error?.let { msg ->
            AppCard { Text(msg, color = ColorTokens.Error) }
        }

        if (isLoading && rows.isEmpty()) {
            TasksSkeleton()
        } else if (filteredRows.isEmpty() && !isLoading) {
            AppCard {
                Text("Tidak ada task sesuai filter.", color = ColorTokens.TextSecondary)
            }
        } else {
            LazyColumn(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                items(filteredRows, key = { it.id }) { row ->
                    TaskCard(
                        row = row,
                        outboxStatus = latestOutboxStatusForTask(taskOutboxRows, row.id),
                        lastAction = latestOutboxActionForTask(taskOutboxRows, row.id),
                        priority = priorityForStatus(row.status),
                        onOpenDetail = { detailTask = row },
                        onApprove = {
                            selectedTask = row
                            selectedAction = TaskActionUi.APPROVE
                        },
                        onReject = {
                            selectedTask = row
                            selectedAction = TaskActionUi.REJECT
                        },
                        onComplete = {
                            selectedTask = row
                            selectedAction = TaskActionUi.COMPLETE
                        },
                        onRetryFailed = {
                            val latestFailed = latestOutboxActionForTask(taskOutboxRows, row.id)
                            if (latestFailed?.status == "FAILED") {
                                scope.launch {
                                    repository.retryOutboxAction(latestFailed.localId)
                                    repository.syncOutboxNow()
                                    toastMessage = "Mengirim..."
                                }
                            }
                        }
                    )
                }
            }
        }
    }

    val currentTask = selectedTask
    val currentAction = selectedAction
    if (currentTask != null && currentAction != null) {
        ModalBottomSheet(
            onDismissRequest = {
                selectedTask = null
                selectedAction = null
                actionNote = ""
            }
        ) {
            Column(
                modifier = Modifier.padding(SpacingTokens.Lg),
                verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
            ) {
                Text(
                    text = "Task Action: ${currentAction.name}",
                    style = MaterialTheme.typography.titleMedium,
                    color = ColorTokens.TextPrimary
                )
                Text(
                    text = "${currentTask.doCode} (${currentTask.status})",
                    style = MaterialTheme.typography.bodyMedium,
                    color = ColorTokens.TextSecondary
                )
                OutlinedTextField(
                    value = actionNote,
                    onValueChange = { actionNote = it },
                    modifier = Modifier
                        .fillMaxWidth()
                        .semantics { contentDescription = "task_action_note" },
                    label = { Text("Note") },
                    placeholder = { Text("Tambah catatan action...") }
                )
                Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                    TextButton(onClick = {
                        selectedTask = null
                        selectedAction = null
                        actionNote = ""
                    }) {
                        Text("Batal")
                    }
                    Button(onClick = {
                        scope.launch {
                            repository.runTaskAction(currentTask, currentAction, actionNote)
                                .onSuccess {
                                    toastMessage = when (it) {
                                        TaskActionDispatch.SENT ->
                                            "Action ${currentAction.name} sukses untuk ${currentTask.doCode}"
                                        TaskActionDispatch.QUEUED ->
                                            "Jaringan tidak stabil, action ${currentAction.name} di-queue untuk sync otomatis."
                                    }
                                    selectedTask = null
                                    selectedAction = null
                                    actionNote = ""
                                    refresh()
                                }
                                .onFailure {
                                    toastMessage = "Action gagal: ${it.message ?: "unknown error"}"
                                }
                        }
                    }) {
                        Text("Submit")
                    }
                }
            }
        }
    }

    val selectedDetail = detailTask
    if (selectedDetail != null) {
        val history = remember(taskOutboxRows, selectedDetail.id) {
            taskOutboxRows.mapNotNull { row ->
                parseTaskPayload(row)?.let { payload ->
                    if (payload.doId == selectedDetail.id) {
                        TaskActionHistory(
                            localId = row.localId,
                            actionCode = payload.actionCode,
                            note = payload.note,
                            status = row.status,
                            createdAtMillis = row.createdAtMillis,
                            lastErrorCode = row.lastErrorCode
                        )
                    } else null
                }
            }
        }
        ModalBottomSheet(onDismissRequest = { detailTask = null }) {
            Column(
                modifier = Modifier.padding(SpacingTokens.Lg),
                verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)
            ) {
                Text("Task Detail", style = MaterialTheme.typography.titleMedium, color = ColorTokens.TextPrimary)
                Text("${selectedDetail.doCode} | ${selectedDetail.status}", color = ColorTokens.TextSecondary)
                Text("Customer: ${selectedDetail.customerCode ?: "-"}", color = ColorTokens.TextSecondary)
                Text("Tracking: ${selectedDetail.trackingCode ?: "-"}", color = ColorTokens.TextSecondary)
                Text("Local Action History", color = ColorTokens.TextPrimary)
                if (history.isEmpty()) {
                    Text("Belum ada history lokal.", color = ColorTokens.TextMuted)
                } else {
                    history.forEach { h ->
                        AppCard(
                            modifier = Modifier.clickable(enabled = h.status == "FAILED") {
                                if (h.status == "FAILED") {
                                    scope.launch {
                                        repository.retryOutboxAction(h.localId)
                                        toastMessage = "Retry action lokal #${h.localId} dikirim."
                                    }
                                }
                            }
                        ) {
                            Text("${h.actionCode} | ${h.status}", color = ColorTokens.TextPrimary)
                            Text(h.note.ifBlank { "-" }, color = ColorTokens.TextSecondary)
                            Text(
                                "err=${h.lastErrorCode ?: "-"} | ts=${h.createdAtMillis}",
                                style = MaterialTheme.typography.bodySmall,
                                color = ColorTokens.TextMuted
                            )
                            if (h.status == "FAILED") {
                                OutlinedButton(
                                    onClick = {
                                        scope.launch {
                                            repository.retryOutboxAction(h.localId)
                                            repository.syncOutboxNow()
                                            toastMessage = "Mengirim..."
                                        }
                                    },
                                    modifier = Modifier.semantics { contentDescription = "task_retry_button" }
                                ) { Text("Coba lagi") }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun TaskCard(
    row: TaskItem,
    outboxStatus: String?,
    lastAction: TaskActionHistory?,
    priority: TaskPriority,
    onOpenDetail: () -> Unit,
    onApprove: () -> Unit,
    onReject: () -> Unit,
    onComplete: () -> Unit,
    onRetryFailed: () -> Unit
) {
    AppCard(
        modifier = Modifier.semantics { contentDescription = "task_list_item" }
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            Text(row.doCode, style = MaterialTheme.typography.titleSmall, color = ColorTokens.TextPrimary)
            Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                StatusBadge(row.status)
                StatusBadge(priority.label)
                outboxStatus?.let { StatusBadge("OUTBOX $it") }
            }
            Text(
                "Customer: ${row.customerCode ?: "-"} | Tracking: ${row.trackingCode ?: "-"}",
                style = MaterialTheme.typography.bodySmall,
                color = ColorTokens.TextSecondary
            )
            Text(
                "Date: ${row.doDate ?: "-"} | Total: ${row.grandTotal ?: "-"}",
                style = MaterialTheme.typography.bodySmall,
                color = ColorTokens.TextMuted
            )
            Text(
                "Aksi terakhir: ${lastAction?.actionCode ?: "Belum ada aksi"} — ${taskStatusLabel(lastAction?.status)} ${timeAgo(lastAction?.createdAtMillis)}",
                style = MaterialTheme.typography.bodySmall,
                color = ColorTokens.TextSecondary
            )
            Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                OutlinedButton(onClick = onOpenDetail) { Text("Detail") }
                Button(onClick = onApprove) { Text("Approve") }
                OutlinedButton(onClick = onReject) { Text("Reject") }
                OutlinedButton(onClick = onComplete) { Text("Complete") }
                if (outboxStatus == "FAILED") {
                    OutlinedButton(
                        onClick = onRetryFailed,
                        modifier = Modifier.semantics { contentDescription = "task_retry_button" }
                    ) {
                        Text("Coba lagi")
                    }
                }
            }
        }
    }
}

private enum class TaskFilter(val label: String) {
    ALL("Semua"),
    PENDING("Menunggu"),
    FAILED("Gagal"),
    SENT("Terkirim");

    companion object {
        fun from(raw: String): TaskFilter = entries.firstOrNull { it.name == raw } ?: ALL
    }
}

private enum class TaskPriority(val label: String) {
    HIGH("HIGH"),
    MEDIUM("MEDIUM"),
    LOW("LOW")
}

private data class OutboxSummary(
    val pending: Int,
    val sent: Int,
    val failed: Int
)

private data class TaskActionHistory(
    val localId: Long,
    val actionCode: String,
    val note: String,
    val status: String,
    val createdAtMillis: Long,
    val lastErrorCode: String?
)

private fun priorityForStatus(status: String): TaskPriority {
    return when (status.lowercase()) {
        "wait_payment", "delivered", "ready_scm" -> TaskPriority.HIGH
        "on_delivery", "wqs_processing" -> TaskPriority.MEDIUM
        else -> TaskPriority.LOW
    }
}

private fun parseTaskPayload(row: OutboxActionEntity): TaskDoActionPayload? {
    return runCatching {
        Json.decodeFromString(TaskDoActionPayload.serializer(), row.payloadJson)
    }.getOrNull()
}

private fun latestOutboxStatusForTask(rows: List<OutboxActionEntity>, doId: Int): String? {
    return rows.firstNotNullOfOrNull { row ->
        val payload = parseTaskPayload(row) ?: return@firstNotNullOfOrNull null
        if (payload.doId == doId) row.status else null
    }
}

private fun latestOutboxActionForTask(rows: List<OutboxActionEntity>, doId: Int): TaskActionHistory? {
    return rows.firstNotNullOfOrNull { row ->
        val payload = parseTaskPayload(row) ?: return@firstNotNullOfOrNull null
        if (payload.doId != doId) return@firstNotNullOfOrNull null
        TaskActionHistory(
            localId = row.localId,
            actionCode = payload.actionCode,
            note = payload.note,
            status = row.status,
            createdAtMillis = row.createdAtMillis,
            lastErrorCode = row.lastErrorCode
        )
    }
}

@Composable
private fun TasksSkeleton() {
    repeat(8) {
        AppCard { Text("Memuat task...", color = ColorTokens.TextMuted) }
    }
}

private fun taskStatusLabel(status: String?): String {
    return when (status) {
        "PENDING", "IN_FLIGHT" -> "Mengantri"
        "FAILED" -> "Gagal"
        "SENT" -> "Terkirim"
        else -> "Belum ada"
    }
}

private fun timeAgo(ts: Long?): String {
    if (ts == null || ts <= 0L) return ""
    val diff = (System.currentTimeMillis() - ts).coerceAtLeast(0L)
    val sec = diff / 1000L
    return when {
        sec < 60 -> "${sec}d"
        sec < 3600 -> "${sec / 60}m"
        sec < 86400 -> "${sec / 3600}j"
        else -> "${sec / 86400}h"
    }
}
