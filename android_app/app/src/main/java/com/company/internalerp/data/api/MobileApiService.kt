package com.company.internalerp.data.api

import kotlinx.serialization.Serializable
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Query

@Serializable
data class LoginRequest(
    val username: String,
    val password: String,
    val device_id: String
)

@Serializable
data class RefreshRequest(
    val refresh_token: String,
    val device_id: String
)

@Serializable
data class ChatSendRequest(
    val channel_id: Int,
    val message_text: String
)

@Serializable
data class StockAdjustmentCreateRequest(
    val product_id: Int,
    val delta_qty: Int,
    val reason: String
)

@Serializable
data class SalesDoActionRequest(
    val do_id: Int,
    val action_code: String,
    val note: String
)

@Serializable
data class NotificationMarkReadRequest(
    val id: Int
)

interface MobileApiService {
    @POST("auth/login")
    suspend fun login(@Body body: LoginRequest): ApiSuccessEnvelope

    @POST("auth/refresh")
    suspend fun refresh(@Body body: RefreshRequest): ApiSuccessEnvelope

    @GET("auth/me")
    suspend fun me(): ApiSuccessEnvelope

    @GET("dashboard/get")
    suspend fun dashboard(): ApiSuccessEnvelope

    @GET("tasks/my")
    suspend fun tasksMy(): ApiSuccessEnvelope

    @POST("sales/do_action")
    suspend fun salesDoAction(
        @Header("X-Idempotency-Key") idempotencyKey: String,
        @Body body: SalesDoActionRequest
    ): ApiSuccessEnvelope

    @GET("notifications/list")
    suspend fun notificationsList(@Query("page") page: Int = 1, @Query("limit") limit: Int = 20): ApiSuccessEnvelope

    @POST("notifications/mark_read")
    suspend fun notificationsMarkRead(
        @Body body: NotificationMarkReadRequest
    ): ApiSuccessEnvelope

    @GET("sales/do_list")
    suspend fun salesDoList(
        @Query("page") page: Int = 1,
        @Query("limit") limit: Int = 20,
        @Query("since") since: Long? = null
    ): ApiSuccessEnvelope

    @GET("chat/channels")
    suspend fun chatChannels(): ApiSuccessEnvelope

    @POST("chat/send")
    suspend fun chatSend(
        @Header("X-Idempotency-Key") idempotencyKey: String,
        @Body body: ChatSendRequest
    ): ApiSuccessEnvelope

    @POST("stock/adjustment_create")
    suspend fun stockAdjustmentCreate(
        @Header("X-Idempotency-Key") idempotencyKey: String,
        @Body body: StockAdjustmentCreateRequest
    ): ApiSuccessEnvelope
}
