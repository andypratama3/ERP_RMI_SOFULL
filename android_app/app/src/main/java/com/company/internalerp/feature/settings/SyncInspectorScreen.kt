package com.company.internalerp.feature.settings

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import com.company.internalerp.core.sync.OutboxSyncWorker
import com.company.internalerp.data.repository_impl.MobileRepository
import com.company.internalerp.data.repository_impl.SyncHealth
import com.company.internalerp.design_system.components.AppCard
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens
import kotlinx.coroutines.launch

@Composable
fun SyncInspectorScreen(repository: MobileRepository) {
    val scope = rememberCoroutineScope()
    val outbox by repository.observeOutboxActions().collectAsState(initial = emptyList())
    var message by remember { mutableStateOf<String?>(null) }
    val syncHealth by produceState(initialValue = SyncHealth(), key1 = outbox.size) {
        value = repository.currentSyncHealth()
    }
    val analyticsTail by produceState(initialValue = emptyList<String>(), key1 = outbox.size) {
        value = repository.readLocalAnalyticsTail()
    }

    val failed = remember(outbox) { outbox.filter { it.status == "FAILED" } }
    val pending = remember(outbox) { outbox.filter { it.status == "PENDING" || it.status == "IN_FLIGHT" } }
    val inFlight = remember(outbox) { outbox.filter { it.status == "IN_FLIGHT" } }
    val taskRows = remember(outbox) { outbox.filter { it.actionType == OutboxSyncWorker.ACTION_TASK_DO_ACTION } }

    Column(
        modifier = Modifier.padding(SpacingTokens.Lg),
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text("Sync Inspector", style = MaterialTheme.typography.titleLarge, color = ColorTokens.TextPrimary)
        AppCard {
            Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Xs)) {
                Text("Pending: ${pending.size}", color = ColorTokens.TextSecondary)
                Text("Failed: ${failed.size}", color = ColorTokens.TextSecondary)
                Text("In flight: ${inFlight.size}", color = ColorTokens.TextSecondary)
                Text("Task queue: ${taskRows.size}", color = ColorTokens.TextSecondary)
                Text("Last sync ts: ${syncHealth.lastSyncTs}", color = ColorTokens.TextSecondary)
                Text("Last error: ${syncHealth.lastErrorCode ?: "-"}", color = ColorTokens.TextSecondary)
                Text("Consecutive failures: ${syncHealth.consecutiveFailures}", color = ColorTokens.TextSecondary)
                syncHealth.warningMessage?.let { Text(it, color = ColorTokens.Warn) }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            Button(onClick = {
                repository.syncOutboxNow()
                message = "Paksa sinkronisasi dijalankan."
            }) { Text("Paksa sinkronisasi") }
            OutlinedButton(onClick = {
                scope.launch {
                    repository.resumeFailedSync()
                    message = "Lanjutkan sinkronisasi gagal dijalankan."
                }
            }) { Text("Lanjutkan sinkronisasi gagal") }
        }
        OutlinedButton(
            onClick = {
                scope.launch {
                    val deleted = repository.cleanupSentOutbox(7)
                    message = "Cleanup SENT selesai: $deleted item."
                }
            },
            modifier = Modifier.fillMaxWidth()
        ) { Text("Cleanup sent items (7 hari)") }

        message?.let { AppCard { Text(it, color = ColorTokens.TextSecondary) } }

        Text("Failed Queue", color = ColorTokens.TextPrimary)
        LazyColumn(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            items(failed, key = { it.localId }) { row ->
                AppCard {
                    Text("ACT#${row.localId} ${row.actionType}", color = ColorTokens.TextPrimary)
                    Text("status=${row.status} attempts=${row.attempts}", color = ColorTokens.TextSecondary)
                    Text("err=${row.lastErrorCode ?: "-"}", color = ColorTokens.TextSecondary)
                    OutlinedButton(onClick = {
                        scope.launch {
                            repository.retryOutboxAction(row.localId)
                            message = "Coba lagi action #${row.localId}."
                        }
                    }) { Text("Coba lagi") }
                }
            }
        }

        Text("Analytics Tail", color = ColorTokens.TextPrimary)
        if (analyticsTail.isEmpty()) {
            Text("Belum ada event lokal.", color = ColorTokens.TextMuted)
        } else {
            analyticsTail.takeLast(5).forEach { line ->
                AppCard { Text(line, color = ColorTokens.TextSecondary) }
            }
        }
    }
}
