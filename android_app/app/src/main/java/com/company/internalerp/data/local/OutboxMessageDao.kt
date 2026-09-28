package com.company.internalerp.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.company.internalerp.data.local.entity.OutboxMessageEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface OutboxMessageDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(row: OutboxMessageEntity): Long

    @Query("SELECT * FROM outbox_messages WHERE status='PENDING' ORDER BY localId ASC")
    suspend fun pending(): List<OutboxMessageEntity>

    @Query("UPDATE outbox_messages SET status=:status, lastErrorCode=:errorCode WHERE localId=:localId")
    suspend fun mark(localId: Long, status: String, errorCode: String?)

    @Query("SELECT * FROM outbox_messages ORDER BY localId DESC")
    fun observeAll(): Flow<List<OutboxMessageEntity>>
}
