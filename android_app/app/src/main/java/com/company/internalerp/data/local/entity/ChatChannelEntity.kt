package com.company.internalerp.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "chat_channels_cache")
data class ChatChannelEntity(
    @PrimaryKey val id: Int,
    val name: String?,
    val unreadCount: Int,
    val mutedUntil: String?,
    val syncedAtMillis: Long
)
