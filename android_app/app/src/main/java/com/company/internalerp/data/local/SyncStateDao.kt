package com.company.internalerp.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.company.internalerp.data.local.entity.SyncStateEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface SyncStateDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(row: SyncStateEntity)

    @Query("SELECT * FROM sync_state ORDER BY key ASC")
    fun observeAll(): Flow<List<SyncStateEntity>>

    @Query("SELECT * FROM sync_state WHERE `key`=:key LIMIT 1")
    suspend fun find(key: String): SyncStateEntity?
}
