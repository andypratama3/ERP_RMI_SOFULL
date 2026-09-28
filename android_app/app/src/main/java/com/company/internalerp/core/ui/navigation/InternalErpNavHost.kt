package com.company.internalerp.core.ui.navigation

import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.Modifier
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import com.company.internalerp.core.util.AppConfig
import com.company.internalerp.data.repository_impl.MobileRepository
import com.company.internalerp.feature.attendance.AttendanceScreen
import com.company.internalerp.feature.chat.ChatScreen
import com.company.internalerp.feature.dashboard.DashboardScreen
import com.company.internalerp.feature.login.LoginScreen
import com.company.internalerp.feature.notifications.NotificationsScreen
import com.company.internalerp.feature.purchases.PurchasesScreen
import com.company.internalerp.feature.sales.SalesScreen
import com.company.internalerp.feature.settings.SettingsScreen
import com.company.internalerp.feature.settings.SyncInspectorScreen
import com.company.internalerp.feature.stock.StockScreen
import com.company.internalerp.feature.tasks.TasksScreen
import com.company.internalerp.feature.transactions.TransactionsScreen

private object Routes {
    const val Login = "login"
    const val Dashboard = "dashboard"
    const val Attendance = "attendance"
    const val Tasks = "tasks"
    const val Transactions = "transactions"
    const val Sales = "sales"
    const val Purchases = "purchases"
    const val Stock = "stock"
    const val Chat = "chat"
    const val Notifications = "notifications"
    const val Settings = "settings"
    const val SyncInspector = "sync_inspector"
}

@Composable
fun InternalErpNavHost(
    navController: NavHostController,
    contentPadding: PaddingValues
) {
    val context = LocalContext.current
    val repository = remember {
        MobileRepository(
            context = context,
            baseUrl = AppConfig.mobileBaseUrl,
            fallbackBaseUrls = AppConfig.mobileFallbackBaseUrls
        )
    }

    NavHost(
        navController = navController,
        startDestination = Routes.Login,
        modifier = Modifier
            .fillMaxSize()
            .padding(contentPadding)
    ) {
        composable(Routes.Login) {
            LoginScreen(repository = repository) {
                navController.navigate(Routes.Dashboard) {
                    popUpTo(Routes.Login) { inclusive = true }
                }
            }
        }
        composable(Routes.Dashboard) {
            DashboardScreen(
                repository = repository,
                openAttendance = { navController.navigate(Routes.Attendance) },
                openTasks = { navController.navigate(Routes.Tasks) },
                openSales = { navController.navigate(Routes.Sales) },
                openPurchases = { navController.navigate(Routes.Purchases) },
                openStock = { navController.navigate(Routes.Stock) },
                openChat = { navController.navigate(Routes.Chat) },
                openNotifications = { navController.navigate(Routes.Notifications) },
                openSettings = { navController.navigate(Routes.Settings) },
                onLogout = {
                    repository.logout()
                    navController.navigate(Routes.Login) {
                        popUpTo(Routes.Dashboard) { inclusive = true }
                    }
                }
            )
        }
        composable(Routes.Attendance) { AttendanceScreen() }
        composable(Routes.Tasks) { TasksScreen(repository = repository) }
        composable(Routes.Transactions) {
            TransactionsScreen(
                openSales = { navController.navigate(Routes.Sales) },
                openPurchases = { navController.navigate(Routes.Purchases) },
                openStock = { navController.navigate(Routes.Stock) }
            )
        }
        composable(Routes.Sales) { SalesScreen(repository = repository) }
        composable(Routes.Purchases) { PurchasesScreen() }
        composable(Routes.Stock) { StockScreen(repository = repository) }
        composable(Routes.Chat) { ChatScreen(repository = repository) }
        composable(Routes.Notifications) { NotificationsScreen(repository = repository) }
        composable(Routes.Settings) {
            SettingsScreen(
                repository = repository,
                openSyncInspector = { navController.navigate(Routes.SyncInspector) },
                onLogout = {
                    repository.logout()
                    navController.navigate(Routes.Login) {
                        popUpTo(Routes.Dashboard) { inclusive = true }
                    }
                }
            )
        }
        composable(Routes.SyncInspector) {
            SyncInspectorScreen(repository = repository)
        }
    }
}
