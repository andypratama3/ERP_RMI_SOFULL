package com.company.internalerp.feature.notifications

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import com.company.internalerp.data.repository_impl.MobileRepository
import com.company.internalerp.data.repository_impl.NotificationItem
import com.company.internalerp.design_system.components.AppCard
import com.company.internalerp.design_system.components.StatusBadge
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens
import kotlinx.coroutines.launch

@Composable
fun NotificationsScreen(repository: MobileRepository) {
    val scope = rememberCoroutineScope()
    var rows by remember { mutableStateOf(emptyList<NotificationItem>()) }
    var isLoading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }

    fun refresh() {
        scope.launch {
            isLoading = true
            repository.fetchNotificationItems()
                .onSuccess {
                    rows = it
                    error = null
                }
                .onFailure {
                    error = it.message ?: "Failed to load notifications."
                }
            isLoading = false
        }
    }

    LaunchedEffect(Unit) {
        refresh()
    }

    Column(
        modifier = Modifier.padding(SpacingTokens.Lg),
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text(
            text = "Notifications",
            style = MaterialTheme.typography.titleLarge,
            color = ColorTokens.TextPrimary
        )
        Text(
            text = "Pusat notifikasi operasional mobile",
            style = MaterialTheme.typography.bodyMedium,
            color = ColorTokens.TextSecondary
        )
        Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            Button(onClick = { refresh() }) {
                Text(if (isLoading) "Loading..." else "Refresh")
            }
            OutlinedButton(onClick = {
                val unread = rows.count { !it.isRead }
                if (unread == 0) {
                    info = "Tidak ada unread notifications."
                    return@OutlinedButton
                }
                scope.launch {
                    isLoading = true
                    val unreadIds = rows.filter { !it.isRead }.map { it.id }
                    repository.markAllNotificationsRead(unreadIds)
                        .onSuccess { info = "Semua notifikasi ditandai sudah dibaca." }
                        .onFailure { info = "Gagal mark all read: ${it.message ?: "unknown error"}" }
                    refresh()
                    isLoading = false
                }
            }) { Text("Mark All Read") }
        }

        info?.let { AppCard { Text(it, color = ColorTokens.TextSecondary) } }
        error?.let { AppCard { Text(it, color = ColorTokens.Error) } }

        if (rows.isEmpty() && !isLoading) {
            AppCard { Text("Belum ada notifikasi.", color = ColorTokens.TextSecondary) }
        } else {
            LazyColumn(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                items(rows, key = { it.id }) { row ->
                    NotificationCard(
                        item = row,
                        onMarkRead = {
                            scope.launch {
                                repository.markNotificationRead(row.id)
                                    .onSuccess {
                                        info = "Notification #${row.id} ditandai read."
                                        refresh()
                                    }
                                    .onFailure {
                                        info = "Gagal mark read: ${it.message ?: "unknown error"}"
                                    }
                            }
                        }
                    )
                }
            }
        }
    }
}

@Composable
private fun NotificationCard(
    item: NotificationItem,
    onMarkRead: () -> Unit
) {
    AppCard {
        Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                Text(
                    text = item.title.ifBlank { "Untitled" },
                    style = MaterialTheme.typography.titleSmall,
                    color = ColorTokens.TextPrimary
                )
                StatusBadge(if (item.isRead) "READ" else "UNREAD")
            }
            item.body?.takeIf { it.isNotBlank() }?.let {
                Text(
                    text = it,
                    style = MaterialTheme.typography.bodySmall,
                    color = ColorTokens.TextSecondary
                )
            }
            Text(
                text = "Code: ${item.notifCode ?: "-"} | ${item.createdAt ?: "-"}",
                style = MaterialTheme.typography.bodySmall,
                color = ColorTokens.TextMuted
            )
            if (!item.isRead) {
                OutlinedButton(onClick = onMarkRead) { Text("Mark Read") }
            }
        }
    }
}
