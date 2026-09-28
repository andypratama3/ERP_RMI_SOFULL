package com.company.internalerp.core.security

import android.content.Context
import android.os.Build
import com.company.internalerp.BuildConfig
import com.company.internalerp.core.db.AppDatabase
import com.company.internalerp.data.local.entity.SyncStateEntity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

object AntiTamperTelemetry {
    fun recordSnapshot(context: Context) {
        val appContext = context.applicationContext
        CoroutineScope(Dispatchers.IO).launch {
            runCatching {
                val db = AppDatabase.get(appContext)
                val payload = """
                    {
                      "ts":${System.currentTimeMillis()},
                      "is_emulator":${isProbablyEmulator()},
                      "is_debuggable":${BuildConfig.DEBUG},
                      "fingerprint":"${Build.FINGERPRINT.take(48)}",
                      "brand":"${Build.BRAND}",
                      "root_indicator":${hasRootIndicator()}
                    }
                """.trimIndent()
                db.syncStateDao().upsert(
                    SyncStateEntity(
                        key = "anti_tamper_snapshot",
                        valueJson = payload,
                        updatedAtMillis = System.currentTimeMillis()
                    )
                )
            }
        }
    }

    private fun isProbablyEmulator(): Boolean {
        return Build.FINGERPRINT.contains("generic", ignoreCase = true) ||
            Build.MODEL.contains("Emulator", ignoreCase = true) ||
            Build.MANUFACTURER.contains("Genymotion", ignoreCase = true)
    }

    private fun hasRootIndicator(): Boolean {
        val tags = Build.TAGS.orEmpty()
        return tags.contains("test-keys", ignoreCase = true)
    }
}
