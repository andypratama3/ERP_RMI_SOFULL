package com.company.internalerp.data.local.entity

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

@Entity(
    tableName = "outbox_actions",
    indices = [Index(value = ["dedupKey"], unique = true)]
)
data class OutboxActionEntity(
    @PrimaryKey(autoGenerate = true) val localId: Long = 0,
    val actionType: String,
    val dedupKey: String,
    val payloadJson: String,
    val idempotencyKey: String,
    val status: String,
    val lastErrorCode: String?,
    val lastErrorMessageMasked: String? = null,
    val lastHttpStatus: Int? = null,
    val inFlightAtMillis: Long? = null,
    val leaseUntilMillis: Long? = null,
    val lastWorkerRunId: String? = null,
    val attempts: Int = 0,
    val nextRetryAtMillis: Long? = null,
    val createdAtMillis: Long
)
