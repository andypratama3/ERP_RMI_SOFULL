package com.company.internalerp.core.util

import com.company.internalerp.BuildConfig

object AppConfig {
    val mobileBaseUrl: String = BuildConfig.MOBILE_BASE_URL
    val mobileFallbackBaseUrls: List<String> = buildList {
        add(BuildConfig.MOBILE_BASE_URL)
        if (BuildConfig.DEBUG) {
            // Keep a single deterministic local route to avoid unexpected port switching.
            add("http://127.0.0.1/ERP_RMI_SOFULL/api/v1/mobile/")
        }
    }.distinct()
}
