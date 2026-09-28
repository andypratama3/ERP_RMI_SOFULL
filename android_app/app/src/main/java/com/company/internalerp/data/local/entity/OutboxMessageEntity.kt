package com.company.internalerp.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "outbox_messages")
data class OutboxMessageEntity(
    @PrimaryKey(autoGenerate = true) val localId: Long = 0,
    val channelId: Int,
    val messageText: String,
    val idempotencyKey: String,
    val status: String,
    val lastErrorCode: String?,
    val createdAtMillis: Long
)
