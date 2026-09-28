package com.company.internalerp

import android.app.Application
import com.company.internalerp.core.sync.NetworkMonitor
import com.company.internalerp.core.sync.SyncScheduler
import com.company.internalerp.core.util.AppConfig
import dagger.hilt.android.HiltAndroidApp
import timber.log.Timber

@HiltAndroidApp
class InternalErpApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Timber.plant(Timber.DebugTree())
        SyncScheduler.ensurePeriodicOutboxSync(this, AppConfig.mobileBaseUrl)
        NetworkMonitor.start(this, AppConfig.mobileBaseUrl)
    }
}
