package com.company.internalerp.core.network

import com.company.internalerp.core.auth.TokenStore
import java.util.UUID
import okhttp3.Authenticator
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject

class RefreshTokenAuthenticator(
    private val tokenStore: TokenStore,
    private val baseUrl: String
) : Authenticator {
    private val refreshLock = Any()

    override fun authenticate(route: okhttp3.Route?, response: Response): Request? {
        if (responseCount(response) >= 2) return null
        val oldAccess = tokenStore.accessToken()
        if (oldAccess.isBlank()) return null
        synchronized(refreshLock) {
            val latestAccess = tokenStore.accessToken()
            if (latestAccess.isNotBlank() && latestAccess != oldAccess) {
                return response.request.newBuilder()
                    .header("Authorization", "Bearer $latestAccess")
                    .build()
            }
            val currentRefresh = tokenStore.refreshToken()
            if (currentRefresh.isBlank()) {
                tokenStore.clear()
                return null
            }

            val refreshResult = refreshToken(currentRefresh)
            if (refreshResult == null) {
                tokenStore.clear()
                return null
            }
            tokenStore.save(refreshResult.first, refreshResult.second)
            return response.request.newBuilder()
                .header("Authorization", "Bearer ${refreshResult.first}")
                .build()
        }
    }

    private fun refreshToken(refreshToken: String): Pair<String, String>? {
        val bodyJson = JSONObject()
            .put("refresh_token", refreshToken)
            .put("device_id", "android-internal-device")
            .toString()
        val req = Request.Builder()
            .url(baseUrl.trimEnd('/') + "/auth/refresh")
            .header("Content-Type", "application/json")
            .header("X-Request-Id", UUID.randomUUID().toString())
            .post(bodyJson.toRequestBody("application/json".toMediaType()))
            .build()

        return runCatching {
            OkHttpClient().newCall(req).execute().use { resp ->
                if (!resp.isSuccessful) return null
                val txt = resp.body?.string().orEmpty()
                val json = JSONObject(txt)
                if (!json.optBoolean("ok")) return null
                val data = json.optJSONObject("data") ?: return null
                val newAccess = data.optString("access_token", "")
                val newRefresh = data.optString("refresh_token", "")
                if (newAccess.isBlank() || newRefresh.isBlank()) return null
                newAccess to newRefresh
            }
        }.getOrNull()
    }

    private fun responseCount(response: Response): Int {
        var count = 1
        var prior = response.priorResponse
        while (prior != null) {
            count++
            prior = prior.priorResponse
        }
        return count
    }
}
