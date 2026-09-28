package com.company.internalerp.core.sync

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.concurrent.atomic.AtomicBoolean

object NetworkMonitor {
    private val started = AtomicBoolean(false)
    private var callback: ConnectivityManager.NetworkCallback? = null
    private var debounceJob: Job? = null
    private const val DEBOUNCE_MS = 15_000L

    fun start(context: Context, baseUrl: String) {
        if (!started.compareAndSet(false, true)) return
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return
        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .build()
        val scope = CoroutineScope(Dispatchers.Default)
        callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                debounceJob?.cancel()
                debounceJob = scope.launch {
                    delay(DEBOUNCE_MS)
                    SyncScheduler.enqueueOutboxSync(context, baseUrl)
                }
            }
        }
        cm.registerNetworkCallback(request, callback!!)
    }
}
