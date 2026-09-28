package com.company.internalerp.feature.attendance

import android.content.Intent
import android.net.Uri
import android.view.ViewGroup
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import java.net.HttpURLConnection
import java.net.URL
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@Composable
fun AttendanceScreen() {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var checking by remember { mutableStateOf(false) }
    var loadingPage by remember { mutableStateOf(false) }
    var status by remember { mutableStateOf("Memuat endpoint Absensi...") }
    var currentUrl by remember { mutableStateOf<String?>(null) }
    var reloadKey by remember { mutableStateOf(0) }

    val candidates = listOf(
        "http://127.0.0.1/ERP_RMI_SOFULL/absensi/",
        "http://127.0.0.1/absensi/",
        "http://127.0.0.1:8080/ERP_RMI_SOFULL/absensi/",
        "http://127.0.0.1:8080/absensi/"
    )

    DisposableEffect(Unit) {
        scope.launch {
            checking = true
            val resolved = resolveFirstReachable(candidates)
            currentUrl = resolved
            status = if (resolved != null) {
                "Endpoint aktif: $resolved"
            } else {
                "Endpoint Absensi belum reachable. Pastikan server (port 80/8080) aktif."
            }
            checking = false
        }
        onDispose { }
    }

    Column(
        modifier = Modifier.padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        Text("Absensi")
        Text(status)

        Button(
            onClick = {
                scope.launch {
                    checking = true
                    val resolved = resolveFirstReachable(candidates)
                    currentUrl = resolved
                    status = if (resolved != null) {
                        "Endpoint aktif: $resolved"
                    } else {
                        "Endpoint Absensi belum reachable. Pastikan server (port 80/8080) aktif."
                    }
                    reloadKey += 1
                    checking = false
                }
            },
            modifier = Modifier.fillMaxWidth(),
            enabled = !checking
        ) {
            Text(if (checking) "Checking..." else "Refresh Endpoint Absensi")
        }

        Button(
            onClick = {
                val target = currentUrl ?: "http://127.0.0.1:8080/absensi/"
                val i = Intent(Intent.ACTION_VIEW, Uri.parse(target))
                context.startActivity(i)
            },
            modifier = Modifier.fillMaxWidth()
        ) {
            Text("Buka di Browser Eksternal")
        }

        Box(modifier = Modifier.fillMaxSize()) {
            val renderUrl = currentUrl
            if (renderUrl != null) {
                AndroidView(
                    modifier = Modifier.fillMaxSize(),
                    factory = { ctx ->
                        WebView(ctx).apply {
                            layoutParams = ViewGroup.LayoutParams(
                                ViewGroup.LayoutParams.MATCH_PARENT,
                                ViewGroup.LayoutParams.MATCH_PARENT
                            )
                            settings.javaScriptEnabled = true
                            settings.domStorageEnabled = true
                            settings.cacheMode = WebSettings.LOAD_DEFAULT
                            webViewClient = object : WebViewClient() {
                                override fun onPageStarted(view: WebView?, url: String?, favicon: android.graphics.Bitmap?) {
                                    loadingPage = true
                                }

                                override fun onPageFinished(view: WebView?, url: String?) {
                                    loadingPage = false
                                }
                            }
                            loadUrl(renderUrl)
                        }
                    },
                    update = { webView ->
                        if (reloadKey >= 0 && webView.url != renderUrl) {
                            webView.loadUrl(renderUrl)
                        }
                    }
                )
                if (loadingPage) {
                    CircularProgressIndicator(modifier = Modifier.padding(12.dp))
                }
            } else {
                Text("Halaman Absensi belum bisa dimuat.")
            }
        }
    }
}

private suspend fun resolveFirstReachable(urls: List<String>): String? = withContext(Dispatchers.IO) {
    for (u in urls) {
        try {
            val conn = (URL(u).openConnection() as HttpURLConnection).apply {
                requestMethod = "GET"
                connectTimeout = 2500
                readTimeout = 2500
                instanceFollowRedirects = true
            }
            val code = conn.responseCode
            conn.disconnect()
            if (code in 200..399) {
                return@withContext u
            }
        } catch (_: Throwable) {
            // Try next candidate URL.
        }
    }
    null
}
