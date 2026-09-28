package com.company.internalerp.core.sync

import android.content.Context
import androidx.work.Constraints
import androidx.work.Data
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.BackoffPolicy
import java.util.concurrent.TimeUnit

object SyncScheduler {
    private const val PERIODIC_NAME = "OUTBOX_PERIODIC_SYNC"
    private const val UNIQUE_SYNC_NOW = "SYNC_NOW"

    fun enqueueOutboxSync(context: Context, baseUrl: String) {
        val req = OneTimeWorkRequestBuilder<OutboxSyncWorker>()
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build()
            )
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 10, TimeUnit.SECONDS)
            .setInputData(Data.Builder().putString(OutboxSyncWorker.KEY_BASE_URL, baseUrl).build())
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(
            UNIQUE_SYNC_NOW,
            ExistingWorkPolicy.REPLACE,
            req
        )
    }

    fun ensurePeriodicOutboxSync(context: Context, baseUrl: String) {
        val req = PeriodicWorkRequestBuilder<OutboxSyncWorker>(15, TimeUnit.MINUTES)
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build()
            )
            .setInputData(Data.Builder().putString(OutboxSyncWorker.KEY_BASE_URL, baseUrl).build())
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            PERIODIC_NAME,
            ExistingPeriodicWorkPolicy.KEEP,
            req
        )
    }
}
