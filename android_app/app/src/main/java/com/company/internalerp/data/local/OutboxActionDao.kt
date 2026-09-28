package com.company.internalerp.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.company.internalerp.data.local.entity.OutboxActionEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface OutboxActionDao {
    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insert(row: OutboxActionEntity): Long

    @Query(
        """
        SELECT * FROM outbox_actions
        WHERE status IN ('PENDING', 'FAILED')
          AND (nextRetryAtMillis IS NULL OR nextRetryAtMillis <= :nowMillis)
        ORDER BY CASE WHEN status='FAILED' THEN 0 ELSE 1 END, localId ASC
        """
    )
    suspend fun pending(nowMillis: Long): List<OutboxActionEntity>

    @Query(
        """
        SELECT * FROM outbox_actions
        WHERE status='IN_FLIGHT' AND leaseUntilMillis IS NOT NULL AND leaseUntilMillis < :nowMillis
        ORDER BY localId ASC
        """
    )
    suspend fun staleInFlight(nowMillis: Long): List<OutboxActionEntity>

    @Query(
        """
        UPDATE outbox_actions
        SET status='FAILED',
            lastErrorCode=:errorCode,
            lastErrorMessageMasked=:errorMessageMasked,
            lastWorkerRunId=:workerRunId,
            inFlightAtMillis=NULL,
            leaseUntilMillis=NULL
        WHERE status='IN_FLIGHT' AND leaseUntilMillis IS NOT NULL AND leaseUntilMillis < :nowMillis
        """
    )
    suspend fun recoverExpiredInFlight(
        nowMillis: Long,
        errorCode: String = "RECOVERED_STALE_INFLIGHT",
        errorMessageMasked: String = "Recovered stale in-flight lease",
        workerRunId: String
    ): Int

    @Query(
        """
        UPDATE outbox_actions
        SET status='IN_FLIGHT',
            inFlightAtMillis=:nowMillis,
            leaseUntilMillis=:leaseUntilMillis,
            lastWorkerRunId=:workerRunId
        WHERE localId=:localId
        """
    )
    suspend fun markInFlight(localId: Long, nowMillis: Long, leaseUntilMillis: Long, workerRunId: String)

    @Query("UPDATE outbox_actions SET status=:status, lastErrorCode=:errorCode WHERE localId=:localId")
    suspend fun mark(localId: Long, status: String, errorCode: String?)

    @Query(
        """
        UPDATE outbox_actions
        SET status='PENDING',
            lastErrorCode=NULL,
            lastErrorMessageMasked=NULL,
            lastHttpStatus=NULL,
            inFlightAtMillis=NULL,
            leaseUntilMillis=NULL
        WHERE localId=:localId
        """
    )
    suspend fun markPending(localId: Long)

    @Query(
        """
        UPDATE outbox_actions
        SET status='SENT',
            lastErrorCode=NULL,
            lastErrorMessageMasked=NULL,
            lastHttpStatus=NULL,
            nextRetryAtMillis=NULL,
            inFlightAtMillis=NULL,
            leaseUntilMillis=NULL,
            lastWorkerRunId=:workerRunId
        WHERE localId=:localId
        """
    )
    suspend fun markSent(localId: Long, workerRunId: String? = null)

    @Query(
        """
        UPDATE outbox_actions
        SET status='FAILED',
            lastErrorCode=:errorCode,
            lastErrorMessageMasked=:errorMessageMasked,
            lastHttpStatus=:httpStatus,
            attempts=:attempts,
            nextRetryAtMillis=:nextRetryAtMillis,
            inFlightAtMillis=NULL,
            leaseUntilMillis=NULL,
            lastWorkerRunId=:workerRunId
        WHERE localId=:localId
        """
    )
    suspend fun markFailed(
        localId: Long,
        errorCode: String,
        errorMessageMasked: String?,
        httpStatus: Int?,
        attempts: Int,
        nextRetryAtMillis: Long,
        workerRunId: String? = null
    )

    @Query("DELETE FROM outbox_actions WHERE status='SENT' AND createdAtMillis < :cutoffMillis")
    suspend fun cleanupSentOlderThan(cutoffMillis: Long): Int

    @Query("SELECT * FROM outbox_actions WHERE status='FAILED' ORDER BY localId ASC")
    suspend fun failed(): List<OutboxActionEntity>

    @Query("SELECT COUNT(*) FROM outbox_actions WHERE status IN ('PENDING','IN_FLIGHT')")
    suspend fun countPending(): Int

    @Query("SELECT COUNT(*) FROM outbox_actions WHERE status='FAILED'")
    suspend fun countFailed(): Int

    @Query(
        """
        UPDATE outbox_actions
        SET status='PENDING',
            nextRetryAtMillis=NULL,
            inFlightAtMillis=NULL,
            leaseUntilMillis=NULL
        WHERE status='FAILED'
        """
    )
    suspend fun promoteAllFailedToPending(): Int

    @Query("SELECT * FROM outbox_actions ORDER BY localId DESC")
    fun observeAll(): Flow<List<OutboxActionEntity>>

    @Query("SELECT * FROM outbox_actions ORDER BY localId DESC")
    suspend fun listAllSnapshot(): List<OutboxActionEntity>
}
