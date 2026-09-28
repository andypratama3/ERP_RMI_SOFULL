package com.company.internalerp.feature.stock

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import com.company.internalerp.data.repository_impl.MobileRepository
import com.company.internalerp.design_system.components.AppCard
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens
import kotlinx.coroutines.launch

@Composable
fun StockScreen(repository: MobileRepository) {
    var productId by remember { mutableStateOf("1") }
    var deltaQty by remember { mutableStateOf("1") }
    var reason by remember { mutableStateOf("Mobile offline adjustment") }
    val scope = rememberCoroutineScope()

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(SpacingTokens.Lg),
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text(
            "Stock Adjustment",
            style = MaterialTheme.typography.titleLarge,
            color = ColorTokens.TextPrimary
        )
        Text(
            "Draft perubahan stok secara aman dengan tampilan clean.",
            style = MaterialTheme.typography.bodySmall,
            color = ColorTokens.TextMuted
        )
        AppCard(modifier = Modifier.fillMaxWidth()) {
            Column(verticalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                OutlinedTextField(
                    value = productId,
                    onValueChange = { productId = it },
                    label = { Text("Product ID") },
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    value = deltaQty,
                    onValueChange = { deltaQty = it },
                    label = { Text("Delta Qty") },
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    value = reason,
                    onValueChange = { reason = it },
                    label = { Text("Reason") },
                    modifier = Modifier.fillMaxWidth()
                )
                Button(
                    onClick = {
                        val pid = productId.toIntOrNull() ?: 0
                        val dq = deltaQty.toIntOrNull() ?: 0
                        if (pid > 0 && dq != 0 && reason.isNotBlank()) {
                            scope.launch { repository.queueStockAdjustment(pid, dq, reason.trim()) }
                        }
                    },
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Text("Queue Stock Adjustment")
                }
            }
        }
    }
}
