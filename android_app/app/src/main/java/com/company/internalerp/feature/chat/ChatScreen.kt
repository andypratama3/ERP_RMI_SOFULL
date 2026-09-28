package com.company.internalerp.feature.chat

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.company.internalerp.data.repository_impl.MobileRepository
import com.company.internalerp.design_system.components.AppCard
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens
import kotlinx.coroutines.launch

@Composable
fun ChatScreen(repository: MobileRepository) {
    val scope = rememberCoroutineScope()
    val rows by repository.observeChannels().collectAsState(initial = emptyList())
    var channelIdText by remember { mutableStateOf("1") }
    var messageText by remember { mutableStateOf("") }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(SpacingTokens.Lg),
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text(
            "Chat Workspace",
            style = MaterialTheme.typography.titleLarge,
            color = ColorTokens.TextPrimary
        )
        Text(
            "Komunikasi tim dengan queue offline dan tampilan modern.",
            style = MaterialTheme.typography.bodySmall,
            color = ColorTokens.TextMuted
        )
        Button(
            onClick = { scope.launch { repository.refreshChatChannelCache() } },
            modifier = Modifier.fillMaxWidth()
        ) {
            Text("Refresh channels")
        }

        AppCard(modifier = Modifier.fillMaxWidth()) {
            Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                OutlinedTextField(
                    value = channelIdText,
                    onValueChange = { channelIdText = it },
                    label = { Text("Channel ID") },
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    value = messageText,
                    onValueChange = { messageText = it },
                    label = { Text("Message (offline queue)") },
                    modifier = Modifier.fillMaxWidth().semantics { contentDescription = "chat_message_input" }
                )
                Button(
                    onClick = {
                        val cid = channelIdText.toIntOrNull() ?: 0
                        if (cid > 0 && messageText.isNotBlank()) {
                            scope.launch {
                                repository.queueChatSend(cid, messageText.trim())
                                messageText = ""
                            }
                        }
                    },
                    modifier = Modifier
                        .fillMaxWidth()
                        .semantics { contentDescription = "chat_send_button" }
                ) { Text("Queue Chat Send") }
            }
        }

        LazyColumn(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            items(rows) { row ->
                AppCard(modifier = Modifier.fillMaxWidth()) {
                    Text(
                        "#${row.id} ${row.name ?: "-"} unread=${row.unreadCount}",
                        style = MaterialTheme.typography.bodyMedium,
                        color = ColorTokens.TextSecondary
                    )
                }
            }
        }
        if (rows.isEmpty()) {
            AppCard(modifier = Modifier.fillMaxWidth()) {
                Text(
                    "Belum ada data",
                    style = MaterialTheme.typography.bodyMedium,
                    color = ColorTokens.TextSecondary
                )
            }
        }
    }
}
