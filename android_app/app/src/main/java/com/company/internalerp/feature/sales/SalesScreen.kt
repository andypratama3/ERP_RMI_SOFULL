package com.company.internalerp.feature.sales

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.company.internalerp.data.repository_impl.MobileRepository
import com.company.internalerp.design_system.components.AppCard
import com.company.internalerp.design_system.components.StatusBadge
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens
import kotlinx.coroutines.launch

@Composable
fun SalesScreen(repository: MobileRepository) {
    val scope = rememberCoroutineScope()
    val rows by repository.observeSalesDo().collectAsState(initial = emptyList())
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(SpacingTokens.Lg),
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text(
            text = "Sales Delivery Orders",
            style = MaterialTheme.typography.titleLarge,
            color = ColorTokens.TextPrimary,
            modifier = Modifier.semantics { contentDescription = "detail_header" }
        )
        Text(
            text = "Realtime list dengan style clean dan cepat dibaca.",
            style = MaterialTheme.typography.bodySmall,
            color = ColorTokens.TextMuted
        )
        Button(
            onClick = { scope.launch { repository.refreshSalesDoCache() } },
            modifier = Modifier.fillMaxWidth()
        ) {
            Text("Refresh from API")
        }

        if (rows.isEmpty()) {
            AppCard {
                Text(
                    text = "Belum ada data",
                    color = ColorTokens.TextSecondary,
                    style = MaterialTheme.typography.bodyMedium
                )
            }
        } else {
            LazyColumn(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                itemsIndexed(rows) { idx, row ->
                    AppCard(
                        modifier = if (idx == 0) {
                            Modifier.fillMaxWidth().semantics { contentDescription = "list_first_item" }
                        } else {
                            Modifier.fillMaxWidth()
                        }
                    ) {
                        Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Xs)) {
                            Text(
                                text = row.doCode,
                                style = MaterialTheme.typography.titleMedium,
                                color = ColorTokens.TextPrimary
                            )
                            Row(
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)
                            ) {
                                Text("Status", style = MaterialTheme.typography.bodySmall, color = ColorTokens.TextSecondary)
                                StatusBadge(status = row.status.ifBlank { "UNKNOWN" })
                                Spacer(modifier = Modifier.weight(1f))
                            }
                        }
                    }
                }
            }
        }
    }
}
