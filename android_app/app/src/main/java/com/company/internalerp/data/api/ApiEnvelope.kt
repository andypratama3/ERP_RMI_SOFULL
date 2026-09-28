package com.company.internalerp.data.api

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject

@Serializable
data class ApiSuccessEnvelope(
    val ok: Boolean,
    val message: String,
    val data: JsonObject,
    val request_id: String
)

@Serializable
data class ApiErrorEnvelope(
    val ok: Boolean,
    val code: String,
    val message: String,
    val request_id: String
)
