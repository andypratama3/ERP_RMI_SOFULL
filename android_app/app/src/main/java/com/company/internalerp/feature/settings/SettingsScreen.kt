package com.company.internalerp.feature.settings

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.company.internalerp.BuildConfig
import com.company.internalerp.core.ui.settings.UiSettingsStore
import com.company.internalerp.core.ui.settings.UiThemeMode
import com.company.internalerp.core.util.AppConfig
import com.company.internalerp.data.repository_impl.MobileRepository
import com.company.internalerp.design_system.components.AppCard
import com.company.internalerp.design_system.components.StatusBadge
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens
import kotlinx.coroutines.launch

@Composable
fun SettingsScreen(
    repository: MobileRepository,
    openSyncInspector: () -> Unit,
    onLogout: () -> Unit
) {
    val context = LocalContext.current.applicationContext
    val uiSettingsStore = remember(context) { UiSettingsStore(context) }
    val scope = rememberCoroutineScope()

    val themeMode by uiSettingsStore.themeMode.collectAsState(initial = UiThemeMode.DARK)
    val highContrast by uiSettingsStore.highContrast.collectAsState(initial = false)
    val outboxMessages by repository.observeOutboxMessages().collectAsState(initial = emptyList())
    val outboxActions by repository.observeOutboxActions().collectAsState(initial = emptyList())

    val env = when {
        AppConfig.mobileBaseUrl.contains("staging", ignoreCase = true) -> "staging"
        AppConfig.mobileBaseUrl.contains("prod", ignoreCase = true) -> "prod"
        else -> "local"
    }

    Column(
        modifier = Modifier.padding(SpacingTokens.Lg),
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text(
            text = "Settings",
            style = MaterialTheme.typography.titleLarge,
            color = ColorTokens.TextPrimary
        )

        AppCard {
            Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                Text("Environment", color = ColorTokens.TextSecondary)
                StatusBadge(status = env.uppercase())
                Text(
                    "Version ${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})",
                    color = ColorTokens.TextSecondary,
                    style = MaterialTheme.typography.bodySmall
                )
            }
        }

        AppCard {
            Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                Text("Theme", color = ColorTokens.TextSecondary)
                if (themeMode == UiThemeMode.DARK) {
                    Button(
                        onClick = { scope.launch { uiSettingsStore.setThemeMode(UiThemeMode.LIGHT) } },
                        modifier = Modifier.fillMaxWidth()
                    ) { Text("Switch to Light Mode") }
                } else {
                    Button(
                        onClick = { scope.launch { uiSettingsStore.setThemeMode(UiThemeMode.DARK) } },
                        modifier = Modifier.fillMaxWidth()
                    ) { Text("Switch to Dark Mode") }
                }
                OutlinedButton(
                    onClick = { scope.launch { uiSettingsStore.setHighContrast(!highContrast) } },
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Text(if (highContrast) "Disable High Contrast" else "Enable High Contrast")
                }
                Switch(
                    checked = highContrast,
                    onCheckedChange = { enabled ->
                        scope.launch { uiSettingsStore.setHighContrast(enabled) }
                    }
                )
            }
        }

        AppCard {
            Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Xs)) {
                Text("Outbox Messages: ${outboxMessages.size}", color = ColorTokens.TextSecondary)
                outboxMessages.take(6).forEach { row ->
                    Text("MSG#${row.localId} ${row.status} ${row.lastErrorCode ?: ""}", style = MaterialTheme.typography.bodySmall)
                }
                Text("Outbox Actions: ${outboxActions.size}", color = ColorTokens.TextSecondary)
                outboxActions.take(6).forEach { row ->
                    Text("ACT#${row.localId} ${row.actionType} ${row.status} ${row.lastErrorCode ?: ""}", style = MaterialTheme.typography.bodySmall)
                }
            }
        }

        OutlinedButton(
            onClick = openSyncInspector,
            modifier = Modifier.fillMaxWidth()
        ) {
            Text("Sync Inspector")
        }

        Button(
            onClick = onLogout,
            modifier = Modifier
                .fillMaxWidth()
                .semantics { contentDescription = "logout_button" }
        ) {
            Text("Logout")
        }
    }
}
