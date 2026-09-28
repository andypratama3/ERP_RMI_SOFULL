package com.company.internalerp.feature.dashboard

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.company.internalerp.data.repository_impl.DashboardSummary
import com.company.internalerp.data.repository_impl.MobileRepository
import com.company.internalerp.data.repository_impl.SyncHealth
import com.company.internalerp.design_system.components.GlassPanel
import com.company.internalerp.design_system.components.MetricTile
import com.company.internalerp.design_system.components.StatusBadge
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.SpacingTokens
import com.company.internalerp.design_system.tokens.TypographyTokens
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

@Composable
fun DashboardScreen(
    repository: MobileRepository,
    openAttendance: () -> Unit,
    openTasks: () -> Unit,
    openSales: () -> Unit,
    openPurchases: () -> Unit,
    openStock: () -> Unit,
    openChat: () -> Unit,
    openNotifications: () -> Unit,
    openSettings: () -> Unit,
    onLogout: () -> Unit
) {
    val scrollState = rememberScrollState()
    val scope = rememberCoroutineScope()
    val outbox by repository.observeOutboxActions().collectAsState(initial = emptyList())
    var isLoading by remember { mutableStateOf(true) }
    var slowNetworkWarning by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var slowWarningJob by remember { mutableStateOf<Job?>(null) }
    var kpi by remember {
        mutableStateOf(
            DashboardSummary(
                salesDo = 0, po = 0, stockAdjustments = 0, apUnpaid = 0,
                taskScmDelivery = 0, taskActFin = 0, taskFinPay = 0, taskWqsReady = 0, department = ""
            )
        )
    }
    val syncHealth by produceState(initialValue = SyncHealth(), key1 = outbox.size) {
        value = repository.currentSyncHealth()
    }

    fun refreshKpi() {
        scope.launch {
            isLoading = true
            slowNetworkWarning = false
            errorMessage = null
            slowWarningJob?.cancel()
            slowWarningJob = launch {
                delay(3_000L)
                if (isLoading) {
                    slowNetworkWarning = true
                }
            }
            repository.fetchDashboardSummary().onSuccess {
                kpi = it
                errorMessage = null
            }.onFailure {
                errorMessage = "Gagal memuat data. Coba lagi."
            }
            isLoading = false
            slowWarningJob?.cancel()
        }
    }

    LaunchedEffect(Unit) { refreshKpi() }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(
                brush = Brush.verticalGradient(
                    colors = listOf(
                        ColorTokens.BgPrimary,
                        ColorTokens.BgSecondary,
                        ColorTokens.BgTertiary
                    )
                )
            )
            .padding(SpacingTokens.Lg)
            .verticalScroll(scrollState)
            .semantics { contentDescription = "dashboard_root" },
        verticalArrangement = Arrangement.spacedBy(SpacingTokens.Md)
    ) {
        Text(
            text = "Dashboard",
            style = MaterialTheme.typography.headlineMedium,
            color = ColorTokens.TextPrimary,
            fontWeight = TypographyTokens.TitleWeight
        )
        Text(
            text = "Pusat kendali operasional",
            style = MaterialTheme.typography.bodyMedium,
            color = ColorTokens.TextMuted
        )

        val tiles = dashboardTilesForDepartment(kpi)
        tiles.chunked(2).forEach { rowTiles ->
            Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                rowTiles.forEach { tile ->
                    MetricTile(
                        label = tile.label,
                        value = tile.value,
                        accent = tile.accent,
                        modifier = Modifier.weight(1f).semantics {
                            contentDescription = "dashboard_kpi_tile_${tile.id}"
                        }
                    )
                }
                if (rowTiles.size == 1) {
                    Spacer(modifier = Modifier.weight(1f))
                }
            }
        }

        GlassPanel(
            title = "Kesehatan Sinkronisasi",
            modifier = Modifier.semantics { contentDescription = "dashboard_sync_health_card" }
        ) {
            Text("Sinkronisasi terakhir: ${syncHealth.lastSyncTs}", color = ColorTokens.TextSecondary)
            Text("Antrian Menunggu: ${syncHealth.pendingCount}", color = ColorTokens.TextSecondary)
            Text("Antrian Gagal: ${syncHealth.failedCount}", color = ColorTokens.TextSecondary)
            if (!syncHealth.lastErrorCode.isNullOrBlank()) {
                Text("Error terakhir: ${syncHealth.lastErrorCode}", color = ColorTokens.Warn)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                OutlinedButton(onClick = { repository.syncOutboxNow() }, modifier = Modifier.weight(1f)) {
                    Text("Paksa sinkronisasi")
                }
                OutlinedButton(
                    onClick = { scope.launch { repository.resumeFailedSync() } },
                    modifier = Modifier.weight(1f)
                ) {
                    Text("Lanjutkan sinkronisasi gagal")
                }
            }
        }

        if (syncHealth.failedCount > 0 || syncHealth.pendingCount > 5 || slowNetworkWarning || !syncHealth.warningMessage.isNullOrBlank()) {
            AppWarningPanel(
                message = if (!syncHealth.warningMessage.isNullOrBlank()) {
                    syncHealth.warningMessage ?: ""
                } else if (slowNetworkWarning) {
                    "Koneksi tidak stabil. Menampilkan data terakhir."
                } else {
                    "Koneksi tidak stabil, beberapa aksi akan diantrikan."
                }
            )
        }

        DashboardAnomalyPanel(
            anomalies = anomalyItemsForRole(kpi, syncHealth),
            onOpenTasks = openTasks
        )

        GlassPanel(title = "Quick Actions") {
            if (isLoading) {
                Text("Memuat data dashboard...", color = ColorTokens.TextMuted)
            }
            errorMessage?.let { Text(it, color = ColorTokens.Error) }
            OutlinedButton(onClick = { refreshKpi() }, modifier = Modifier.fillMaxWidth()) {
                Text("Refresh KPI")
            }
            DashboardAction("Absensi", onClick = {
                scope.launch { repository.logLocalAnalyticsEvent("attendance") }
                openAttendance()
            })
            DashboardAction("Tasks", onClick = {
                scope.launch { repository.logLocalAnalyticsEvent("tasks") }
                openTasks()
            })
            DashboardAction(
                "Sales",
                onClick = {
                    scope.launch { repository.logLocalAnalyticsEvent("sales") }
                    openSales()
                },
                modifier = Modifier.semantics { contentDescription = "nav_sales" }
            )
            DashboardAction(
                "Purchases",
                onClick = {
                    scope.launch { repository.logLocalAnalyticsEvent("purchases") }
                    openPurchases()
                },
                modifier = Modifier.semantics { contentDescription = "nav_purchases" }
            )
            DashboardAction(
                "Stock",
                onClick = {
                    scope.launch { repository.logLocalAnalyticsEvent("stock") }
                    openStock()
                },
                modifier = Modifier.semantics { contentDescription = "nav_stock" }
            )
            DashboardAction(
                "Chat",
                onClick = {
                    scope.launch { repository.logLocalAnalyticsEvent("chat") }
                    openChat()
                },
                modifier = Modifier.semantics { contentDescription = "nav_chat" }
            )
            DashboardAction("Notifications", onClick = {
                scope.launch { repository.logLocalAnalyticsEvent("notifications") }
                openNotifications()
            })
            DashboardAction("Settings", onClick = {
                scope.launch { repository.logLocalAnalyticsEvent("settings") }
                openSettings()
            })
        }

        GlassPanel(title = "System Status") {
            StatusRow(name = "API Gateway", status = "OK")
            StatusRow(name = "Queue Sync", status = if (kpi.taskWqsReady + kpi.taskScmDelivery > 0) "WARN" else "OK")
            StatusRow(name = "Security Checks", status = "OK")
        }

        OutlinedButton(
            onClick = onLogout,
            modifier = Modifier
                .fillMaxWidth()
                .semantics { contentDescription = "logout_button" }
        ) {
            Text("Logout")
        }
        Spacer(modifier = Modifier.height(SpacingTokens.Sm))
    }
}

private data class DashboardTile(
    val id: String,
    val label: String,
    val value: Int,
    val accent: androidx.compose.ui.graphics.Color
)

@Composable
private fun AppWarningPanel(message: String) {
    GlassPanel(title = "Peringatan") {
        Text(message, color = ColorTokens.Warn)
    }
}

private fun dashboardTilesForDepartment(kpi: DashboardSummary): List<DashboardTile> {
    return when (kpi.department) {
        "SCM" -> listOf(
            DashboardTile("po_pending", "PO Menunggu", kpi.po, ColorTokens.NeonPurple),
            DashboardTile("delivery_scm", "Delivery SCM", kpi.taskScmDelivery, ColorTokens.NeonCyan),
            DashboardTile("stock_alert", "Alert Stok", kpi.stockAdjustments, ColorTokens.Error)
        )
        "ACT" -> listOf(
            DashboardTile("ap_unpaid", "AP Belum Bayar", kpi.apUnpaid, ColorTokens.NeonBlue),
            DashboardTile("task_act", "Task ACT", kpi.taskActFin, ColorTokens.NeonPurple),
            DashboardTile("sales_do", "Sales DO", kpi.salesDo, ColorTokens.NeonCyan)
        )
        "FIN" -> listOf(
            DashboardTile("ap_unpaid", "AP Belum Bayar", kpi.apUnpaid, ColorTokens.NeonBlue),
            DashboardTile("task_fin", "Task FIN", kpi.taskFinPay, ColorTokens.NeonPurple),
            DashboardTile("po_pending", "PO Menunggu", kpi.po, ColorTokens.NeonCyan)
        )
        "WQS" -> listOf(
            DashboardTile("task_wqs", "Task WQS", kpi.taskWqsReady, ColorTokens.NeonPurple),
            DashboardTile("stock_alert", "Alert Stok", kpi.stockAdjustments, ColorTokens.Error),
            DashboardTile("po_pending", "PO Menunggu", kpi.po, ColorTokens.NeonCyan)
        )
        else -> listOf(
            DashboardTile("sales_do", "Sales DO", kpi.salesDo, ColorTokens.NeonCyan),
            DashboardTile("po_pending", "PO Menunggu", kpi.po, ColorTokens.NeonPurple),
            DashboardTile("stock_alert", "Alert Stok", kpi.stockAdjustments, ColorTokens.Error),
            DashboardTile("ap_unpaid", "AP Belum Bayar", kpi.apUnpaid, ColorTokens.NeonBlue)
        )
    }
}

private data class DashboardAnomaly(
    val title: String,
    val severity: String
)

@Composable
private fun DashboardAnomalyPanel(
    anomalies: List<DashboardAnomaly>,
    onOpenTasks: () -> Unit
) {
    GlassPanel(title = "Anomali Prioritas Hari Ini") {
        if (anomalies.isEmpty()) {
            Text("Tidak ada anomali prioritas.", color = ColorTokens.TextSecondary)
        } else {
            anomalies.take(3).forEach { item ->
                Row(horizontalArrangement = Arrangement.spacedBy(SpacingTokens.Sm)) {
                    StatusBadge(item.severity)
                    Text(item.title, color = ColorTokens.TextSecondary)
                }
            }
            OutlinedButton(onClick = onOpenTasks, modifier = Modifier.fillMaxWidth()) {
                Text("Buka Tasks")
            }
        }
    }
}

private fun anomalyItemsForRole(kpi: DashboardSummary, health: SyncHealth): List<DashboardAnomaly> {
    val list = mutableListOf<DashboardAnomaly>()
    if (health.failedCount > 0) {
        list += DashboardAnomaly("Sinkronisasi gagal ${health.failedCount} item", "WARN")
    }
    when (kpi.department) {
        "SCM" -> {
            if (kpi.taskScmDelivery > 5) list += DashboardAnomaly("Delivery SCM tertunda", "WARN")
            if (kpi.stockAdjustments > 0) list += DashboardAnomaly("Anomali stok terdeteksi", "FAIL")
        }
        "ACT", "FIN" -> {
            if (kpi.apUnpaid > 0) list += DashboardAnomaly("Pembayaran pending", "WARN")
            if (kpi.taskActFin + kpi.taskFinPay > 7) list += DashboardAnomaly("Antrian ACT/FIN tinggi", "WARN")
        }
        "WQS" -> {
            if (kpi.taskWqsReady > 5) list += DashboardAnomaly("Antrian WQS tinggi", "WARN")
            if (kpi.stockAdjustments > 0) list += DashboardAnomaly("Stok negatif/anomali", "FAIL")
        }
    }
    return list
}

@Composable
private fun DashboardAction(
    title: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier
) {
    Button(
        onClick = onClick,
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp)
    ) {
        Text(title)
    }
}

@Composable
private fun StatusRow(
    name: String,
    status: String
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(name, color = ColorTokens.TextSecondary)
        StatusBadge(status = status)
    }
}
