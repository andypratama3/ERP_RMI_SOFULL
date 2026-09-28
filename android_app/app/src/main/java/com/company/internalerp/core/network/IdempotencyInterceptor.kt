package com.company.internalerp.core.network

import okhttp3.Interceptor
import okhttp3.Response

class IdempotencyInterceptor : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        val key = request.header("X-Idempotency-Key")
        val needIdem = request.method.equals("POST", ignoreCase = true)
        val req = if (needIdem && key.isNullOrBlank()) {
            request.newBuilder().header("X-Idempotency-Key", java.util.UUID.randomUUID().toString()).build()
        } else {
            request
        }
        return chain.proceed(req)
    }
}
