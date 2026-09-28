package com.company.internalerp.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "sales_do_cache")
data class SalesDoEntity(
    @PrimaryKey val id: Int,
    val doCode: String,
    val trackingCode: String?,
    val status: String,
    val customerCode: String?,
    val updatedAt: String?,
    val syncedAtMillis: Long,
    val serverUpdatedAt: String? = null,
    val localCachedAtMillis: Long = syncedAtMillis,
    val needsRefresh: Int = 0
)
