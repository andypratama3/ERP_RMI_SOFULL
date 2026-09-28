package com.company.internalerp.core.network

import java.util.UUID
import okhttp3.Interceptor
import okhttp3.Response

class RequestIdInterceptor : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val requestId = UUID.randomUUID().toString()
        val req = chain.request().newBuilder()
            .header("X-Request-Id", requestId)
            .build()
        return chain.proceed(req)
    }
}
