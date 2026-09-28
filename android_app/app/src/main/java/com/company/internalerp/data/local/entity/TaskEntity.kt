package com.company.internalerp.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "tasks_cache")
data class TaskEntity(
    @PrimaryKey val id: Int,
    val doCode: String,
    val trackingCode: String?,
    val status: String,
    val customerCode: String?,
    val doDate: String?,
    val grandTotal: String?,
    val syncedAtMillis: Long
)
