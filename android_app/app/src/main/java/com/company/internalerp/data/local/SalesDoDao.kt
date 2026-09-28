package com.company.internalerp.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.company.internalerp.data.local.entity.SalesDoEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface SalesDoDao {
    @Query("SELECT * FROM sales_do_cache ORDER BY id DESC")
    fun observeAll(): Flow<List<SalesDoEntity>>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(rows: List<SalesDoEntity>)

    @Query("SELECT MAX(localCachedAtMillis) FROM sales_do_cache")
    suspend fun lastCachedAtMillis(): Long?

    @Query("UPDATE sales_do_cache SET needsRefresh=:needsRefresh WHERE id=:id")
    suspend fun setNeedsRefresh(id: Int, needsRefresh: Int)

    @Query("UPDATE sales_do_cache SET needsRefresh=0")
    suspend fun clearNeedsRefresh()

    @Query("DELETE FROM sales_do_cache")
    suspend fun clearAll()
}
