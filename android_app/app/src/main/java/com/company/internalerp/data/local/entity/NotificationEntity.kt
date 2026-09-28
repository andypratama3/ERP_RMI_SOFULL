package com.company.internalerp.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "notifications_cache")
data class NotificationEntity(
    @PrimaryKey val id: Int,
    val notifCode: String?,
    val title: String,
    val body: String?,
    val isRead: Int,
    val createdAt: String?,
    val syncedAtMillis: Long
)
