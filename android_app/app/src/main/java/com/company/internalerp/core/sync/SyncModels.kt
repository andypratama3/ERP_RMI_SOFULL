package com.company.internalerp.core.sync

import kotlinx.serialization.Serializable

@Serializable
data class StockAdjustmentPayload(
    val productId: Int,
    val deltaQty: Int,
    val reason: String
)

@Serializable
data class TaskDoActionPayload(
    val doId: Int,
    val actionCode: String,
    val note: String
)
