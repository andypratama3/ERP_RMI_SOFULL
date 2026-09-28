package com.company.internalerp.feature.purchases

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.company.internalerp.design_system.components.AppCard
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens

@Composable
fun PurchasesScreen() {
    val demoRows = listOf("PO-DEMO-001", "PO-DEMO-002")
    var selected by remember { mutableStateOf<String?>(null) }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(SpacingTokens.Lg),
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text(
            "Purchases Hub",
            style = MaterialTheme.typography.titleLarge,
            color = ColorTokens.TextPrimary,
            modifier = Modifier.semantics { contentDescription = "detail_header" }
        )
        Text(
            "PR, PO, dan AP tampil ringkas dengan kartu modern.",
            style = MaterialTheme.typography.bodySmall,
            color = ColorTokens.TextMuted
        )
        LazyColumn {
            itemsIndexed(demoRows) { idx, row ->
                AppCard(
                    modifier = Modifier
                        .fillMaxWidth()
                        .semantics {
                            if (idx == 0) contentDescription = "list_first_item"
                        }
                        .clickable { selected = row }
                ) {
                    Text(
                        row,
                        style = MaterialTheme.typography.bodyLarge,
                        color = ColorTokens.TextPrimary
                    )
                }
            }
        }
        if (selected != null) {
            AppCard(modifier = Modifier.fillMaxWidth()) {
                Text(
                    "Detail: $selected",
                    modifier = Modifier
                        .semantics { contentDescription = "detail_header" },
                    style = MaterialTheme.typography.bodyMedium,
                    color = ColorTokens.TextSecondary
                )
            }
        }
    }
}
