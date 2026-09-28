package com.company.internalerp.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.company.internalerp.data.local.entity.ChatChannelEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface ChatChannelDao {
    @Query("SELECT * FROM chat_channels_cache ORDER BY id DESC")
    fun observeAll(): Flow<List<ChatChannelEntity>>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(rows: List<ChatChannelEntity>)

    @Query("DELETE FROM chat_channels_cache")
    suspend fun clearAll()
}
