package com.company.internalerp.core.network

import android.content.Context
import com.company.internalerp.BuildConfig
import com.company.internalerp.core.auth.TokenStore
import com.company.internalerp.data.api.MobileApiService
import java.util.concurrent.TimeUnit
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.logging.HttpLoggingInterceptor.Logger
import okhttp3.CertificatePinner
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit

object NetworkFactory {
    fun createApi(context: Context, baseUrl: String): MobileApiService {
        val tokenStore = TokenStore(context)
        val maskedLogger = Logger { msg ->
            val safe = Redaction.redactSensitive(msg)
            if (BuildConfig.DEBUG) {
                android.util.Log.d("Net", safe)
            }
        }
        val logger = HttpLoggingInterceptor(maskedLogger).apply { level = HttpLoggingInterceptor.Level.BASIC }
        val builder = OkHttpClient.Builder()
            .addInterceptor(AdaptiveRetryInterceptor())
            .addInterceptor(RequestIdInterceptor())
            .addInterceptor(IdempotencyInterceptor())
            .addInterceptor(AuthInterceptor(tokenStore))
            .authenticator(RefreshTokenAuthenticator(tokenStore, baseUrl))
            .addInterceptor(logger)
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(20, TimeUnit.SECONDS)
            .callTimeout(35, TimeUnit.SECONDS)

        val pinMode = BuildConfig.CERT_PIN_MODE.uppercase()
        val pinningConfigured = BuildConfig.ENABLE_CERT_PINNING &&
            BuildConfig.CERT_PIN_HOST.isNotBlank() &&
            BuildConfig.CERT_PIN_SHA256.isNotBlank()
        if (pinningConfigured && pinMode == "ENFORCE") {
            val pinner = CertificatePinner.Builder()
                .add(BuildConfig.CERT_PIN_HOST, "sha256/${BuildConfig.CERT_PIN_SHA256}")
                .build()
            builder.certificatePinner(pinner)
        } else if (pinningConfigured && pinMode == "REPORT_ONLY") {
            if (BuildConfig.DEBUG) {
                android.util.Log.w("Net", "CERT_PIN_MODE=REPORT_ONLY (non-blocking)")
            }
        }
        val client = builder.build()

        val json = Json { ignoreUnknownKeys = true }
        val retrofit = Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(client)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
        return retrofit.create(MobileApiService::class.java)
    }
}
