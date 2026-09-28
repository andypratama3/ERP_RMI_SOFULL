package com.company.internalerp.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.company.internalerp.data.local.entity.NotificationEntity

@Dao
interface NotificationDao {
    @Query("SELECT * FROM notifications_cache ORDER BY id DESC")
    suspend fun listAll(): List<NotificationEntity>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(rows: List<NotificationEntity>)

    @Query("UPDATE notifications_cache SET isRead = 1 WHERE id = :id")
    suspend fun markRead(id: Int)
}
