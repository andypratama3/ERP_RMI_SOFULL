package com.company.internalerp.core.ui.settings

import android.content.Context
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.MutablePreferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.uiSettingsDataStore by preferencesDataStore(name = "ui_settings")

enum class UiThemeMode { DARK, LIGHT }

class UiSettingsStore(private val context: Context) {
    private object Keys {
        val ThemeMode = stringPreferencesKey("theme_mode")
        val HighContrast = booleanPreferencesKey("high_contrast")
        const val TasksFilterPrefix = "tasks_filter_last_"
        const val TasksSearchPrefix = "tasks_search_last_"
    }

    val themeMode: Flow<UiThemeMode> = context.uiSettingsDataStore.data.map { prefs ->
        when (prefs[Keys.ThemeMode]) {
            UiThemeMode.LIGHT.name -> UiThemeMode.LIGHT
            else -> UiThemeMode.DARK
        }
    }

    val highContrast: Flow<Boolean> = context.uiSettingsDataStore.data.map { prefs ->
        prefs[Keys.HighContrast] ?: false
    }

    suspend fun setThemeMode(mode: UiThemeMode) {
        context.uiSettingsDataStore.edit { prefs: MutablePreferences ->
            prefs[Keys.ThemeMode] = mode.name
        }
    }

    suspend fun setHighContrast(enabled: Boolean) {
        context.uiSettingsDataStore.edit { prefs: MutablePreferences ->
            prefs[Keys.HighContrast] = enabled
        }
    }

    fun tasksFilter(userHash: String): Flow<String> {
        val key = stringPreferencesKey(Keys.TasksFilterPrefix + userHash)
        return context.uiSettingsDataStore.data.map { prefs -> prefs[key] ?: "ALL" }
    }

    fun tasksSearch(userHash: String): Flow<String> {
        val key = stringPreferencesKey(Keys.TasksSearchPrefix + userHash)
        return context.uiSettingsDataStore.data.map { prefs -> prefs[key] ?: "" }
    }

    suspend fun setTasksFilter(userHash: String, filter: String) {
        val key = stringPreferencesKey(Keys.TasksFilterPrefix + userHash)
        context.uiSettingsDataStore.edit { prefs: MutablePreferences ->
            prefs[key] = filter
        }
    }

    suspend fun setTasksSearch(userHash: String, search: String) {
        val key = stringPreferencesKey(Keys.TasksSearchPrefix + userHash)
        context.uiSettingsDataStore.edit { prefs: MutablePreferences ->
            prefs[key] = search
        }
    }
}
