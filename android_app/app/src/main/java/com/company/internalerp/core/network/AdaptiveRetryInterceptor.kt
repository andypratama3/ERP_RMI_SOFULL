package com.company.internalerp.core.network

import kotlin.math.pow
import kotlin.random.Random
import okhttp3.Interceptor
import okhttp3.Response
import java.io.IOException

class AdaptiveRetryInterceptor : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        val isIdempotent = request.method.equals("GET", ignoreCase = true) ||
            request.method.equals("HEAD", ignoreCase = true)
        if (!isIdempotent) {
            return chain.proceed(request)
        }
        var attempt = 0
        var lastError: IOException? = null
        while (attempt <= MAX_RETRY) {
            try {
                val response = chain.proceed(request)
                if (response.code !in RETRYABLE_STATUS || attempt == MAX_RETRY) {
                    return response
                }
                response.close()
            } catch (io: IOException) {
                lastError = io
                if (attempt == MAX_RETRY) throw io
            }
            attempt++
            Thread.sleep(backoffMillis(attempt))
        }
        throw lastError ?: IOException("Retry exhausted")
    }

    private fun backoffMillis(attempt: Int): Long {
        val base = (2.0.pow(attempt.toDouble()) * 250L).toLong().coerceAtMost(2_500L)
        val jitter = Random.nextLong(0L, 250L)
        return base + jitter
    }

    companion object {
        private const val MAX_RETRY = 2
        private val RETRYABLE_STATUS = setOf(502, 503, 504)
    }
}
