package com.company.internalerp.feature.transactions

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import com.company.internalerp.design_system.components.AppCard
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens

private enum class TransactionTab(val label: String) {
    Sales("Sales"),
    Purchases("Purchases"),
    Stock("Stock"),
}

@Composable
fun TransactionsScreen(
    openSales: () -> Unit,
    openPurchases: () -> Unit,
    openStock: () -> Unit,
) {
    var activeTab by remember { mutableStateOf(TransactionTab.Sales) }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(SpacingTokens.Lg),
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text(
            text = "Transactions",
            style = MaterialTheme.typography.titleLarge,
            color = ColorTokens.TextPrimary
        )
        Text(
            text = "Segmented workspace: Sales | Purchases | Stock",
            style = MaterialTheme.typography.bodySmall,
            color = ColorTokens.TextMuted
        )

        Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            TransactionTab.entries.forEach { tab ->
                val selected = activeTab == tab
                if (selected) {
                    Button(
                        onClick = { activeTab = tab },
                        modifier = Modifier.weight(1f)
                    ) { Text(tab.label) }
                } else {
                    OutlinedButton(
                        onClick = { activeTab = tab },
                        modifier = Modifier.weight(1f)
                    ) { Text(tab.label) }
                }
            }
        }

        when (activeTab) {
            TransactionTab.Sales -> SegmentCard(
                title = "Sales Delivery Orders",
                subtitle = "Pantau DO list, status, dan detail per customer.",
                cta = "Open Sales",
                onOpen = openSales
            )

            TransactionTab.Purchases -> SegmentCard(
                title = "Purchases",
                subtitle = "Kelola PR/PO/AP secara ringkas dan terstruktur.",
                cta = "Open Purchases",
                onOpen = openPurchases
            )

            TransactionTab.Stock -> SegmentCard(
                title = "Stock & Adjustment",
                subtitle = "Lihat stok dan submit adjustment draft dengan aman.",
                cta = "Open Stock",
                onOpen = openStock
            )
        }
    }
}

@Composable
private fun SegmentCard(
    title: String,
    subtitle: String,
    cta: String,
    onOpen: () -> Unit
) {
    AppCard {
        Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
            Text(title, style = MaterialTheme.typography.titleMedium, color = ColorTokens.TextPrimary)
            Text(subtitle, style = MaterialTheme.typography.bodyMedium, color = ColorTokens.TextSecondary)
            Button(onClick = onOpen, modifier = Modifier.fillMaxWidth()) {
                Text(cta)
            }
        }
    }
}
