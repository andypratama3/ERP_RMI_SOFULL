package com.company.internalerp.core.ui

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CenterAlignedTopAppBar
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.navigation.NavDestination
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.company.internalerp.core.ui.navigation.InternalErpNavHost
import com.company.internalerp.core.ui.settings.UiSettingsStore
import com.company.internalerp.core.ui.settings.UiThemeMode

private const val RouteLogin = "login"
private const val RouteDashboard = "dashboard"
private const val RouteTasks = "tasks"
private const val RouteTransactions = "transactions"
private const val RouteSales = "sales"
private const val RoutePurchases = "purchases"
private const val RouteStock = "stock"
private const val RouteChat = "chat"
private const val RouteSettings = "settings"

private data class BottomNavItem(
    val route: String,
    val label: String,
    val iconText: String,
    val selectedRoutes: Set<String> = setOf(route)
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppRoot() {
    val context = LocalContext.current.applicationContext
    val uiSettingsStore = remember(context) { UiSettingsStore(context) }
    val themeMode by uiSettingsStore.themeMode.collectAsState(initial = UiThemeMode.DARK)
    val highContrast by uiSettingsStore.highContrast.collectAsState(initial = false)

    val navController = rememberNavController()
    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentDestination = backStackEntry?.destination
    val showBottomBar = currentDestination?.route != RouteLogin

    val bottomItems = listOf(
        BottomNavItem(route = RouteDashboard, label = "Dashboard", iconText = "D"),
        BottomNavItem(route = RouteTasks, label = "Tasks", iconText = "T"),
        BottomNavItem(
            route = RouteTransactions,
            label = "Transactions",
            iconText = "X",
            selectedRoutes = setOf(RouteTransactions, RouteSales, RoutePurchases, RouteStock)
        ),
        BottomNavItem(route = RouteChat, label = "Chat", iconText = "C"),
        BottomNavItem(route = RouteSettings, label = "Settings", iconText = "S")
    )

    val futuristicDarkScheme = darkColorScheme(
        primary = Color(0xFF23D7C8),
        secondary = Color(0xFFA982FF),
        tertiary = Color(0xFF4FC3F7),
        background = Color(0xFF080A12),
        surface = Color(0xFF111827),
        onPrimary = Color(0xFF04120F),
        onSecondary = Color(0xFF11081F),
        onBackground = Color(0xFFEAF2FF),
        onSurface = Color(0xFFEAF2FF)
    )
    val futuristicLightScheme = lightColorScheme(
        primary = Color(0xFF0FA89A),
        secondary = Color(0xFF7B54D7),
        tertiary = Color(0xFF1976D2),
        background = Color(0xFFF4F7FB),
        surface = Color(0xFFFFFFFF),
        onPrimary = Color(0xFFFFFFFF),
        onSecondary = Color(0xFFFFFFFF),
        onBackground = Color(0xFF162033),
        onSurface = Color(0xFF162033)
    )
    val highContrastDarkScheme = darkColorScheme(
        primary = Color(0xFF5BFFF3),
        secondary = Color(0xFFD0B0FF),
        tertiary = Color(0xFF8FD7FF),
        background = Color(0xFF04050A),
        surface = Color(0xFF0A0E1A),
        onPrimary = Color(0xFF000000),
        onSecondary = Color(0xFF000000),
        onBackground = Color(0xFFFFFFFF),
        onSurface = Color(0xFFFFFFFF)
    )
    val highContrastLightScheme = lightColorScheme(
        primary = Color(0xFF006B62),
        secondary = Color(0xFF5230A8),
        tertiary = Color(0xFF005DAA),
        background = Color(0xFFFFFFFF),
        surface = Color(0xFFFFFFFF),
        onPrimary = Color(0xFFFFFFFF),
        onSecondary = Color(0xFFFFFFFF),
        onBackground = Color(0xFF000000),
        onSurface = Color(0xFF000000)
    )

    val colorScheme = when {
        highContrast && themeMode == UiThemeMode.LIGHT -> highContrastLightScheme
        highContrast && themeMode == UiThemeMode.DARK -> highContrastDarkScheme
        themeMode == UiThemeMode.LIGHT -> futuristicLightScheme
        else -> futuristicDarkScheme
    }

    MaterialTheme(colorScheme = colorScheme) {
        Scaffold(
            modifier = Modifier.fillMaxSize(),
            topBar = {
                CenterAlignedTopAppBar(
                    title = {
                        Text(
                            text = "ERP RMI - Mobile",
                            fontWeight = FontWeight.SemiBold
                        )
                    },
                    colors = TopAppBarDefaults.centerAlignedTopAppBarColors(
                        containerColor = Color(0xFF0E1424),
                        titleContentColor = Color(0xFFEAF2FF)
                    )
                )
            },
            bottomBar = {
                if (showBottomBar) {
                    Surface(color = Color(0xCC0E1424)) {
                        NavigationBar(
                            containerColor = Color(0xAA0E1424),
                            contentColor = Color(0xFFEAF2FF)
                        ) {
                            bottomItems.forEach { item ->
                                val selected = currentDestination.isRouteSelected(item.selectedRoutes)
                                NavigationBarItem(
                                    selected = selected,
                                    onClick = { navController.navigateToBottom(item.route) },
                                    icon = { Text(item.iconText) },
                                    label = { Text(item.label) }
                                )
                            }
                        }
                    }
                }
            }
        ) { padding ->
            InternalErpNavHost(navController = navController, contentPadding = padding)
        }
    }
}

private fun NavDestination?.isRouteSelected(routes: Set<String>): Boolean {
    val destination = this ?: return false
    return destination.hierarchy.any { node -> routes.contains(node.route) }
}

private fun NavHostController.navigateToBottom(route: String) {
    navigate(route) {
        popUpTo(graph.findStartDestination().id) {
            saveState = true
        }
        launchSingleTop = true
        restoreState = true
    }
}
