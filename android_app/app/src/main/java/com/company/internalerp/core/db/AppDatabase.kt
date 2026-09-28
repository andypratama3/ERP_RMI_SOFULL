package com.company.internalerp.core.db

import android.content.Context
import androidx.room.Database
import androidx.room.migration.Migration
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.sqlite.db.SupportSQLiteDatabase
import com.company.internalerp.data.local.NotificationDao
import com.company.internalerp.data.local.ChatChannelDao
import com.company.internalerp.data.local.OutboxActionDao
import com.company.internalerp.data.local.OutboxMessageDao
import com.company.internalerp.data.local.SalesDoDao
import com.company.internalerp.data.local.SyncStateDao
import com.company.internalerp.data.local.TaskDao
import com.company.internalerp.data.local.entity.ChatChannelEntity
import com.company.internalerp.data.local.entity.NotificationEntity
import com.company.internalerp.data.local.entity.OutboxActionEntity
import com.company.internalerp.data.local.entity.OutboxMessageEntity
import com.company.internalerp.data.local.entity.SalesDoEntity
import com.company.internalerp.data.local.entity.SyncStateEntity
import com.company.internalerp.data.local.entity.TaskEntity

@Database(
    entities = [
        SalesDoEntity::class,
        TaskEntity::class,
        ChatChannelEntity::class,
        NotificationEntity::class,
        OutboxMessageEntity::class,
        OutboxActionEntity::class,
        SyncStateEntity::class
    ],
    version = 5,
    exportSchema = false
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun salesDoDao(): SalesDoDao
    abstract fun taskDao(): TaskDao
    abstract fun chatChannelDao(): ChatChannelDao
    abstract fun notificationDao(): NotificationDao
    abstract fun outboxMessageDao(): OutboxMessageDao
    abstract fun outboxActionDao(): OutboxActionDao
    abstract fun syncStateDao(): SyncStateDao

    companion object {
        @Volatile private var instance: AppDatabase? = null
        private val MIGRATION_1_2 = object : Migration(1, 2) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS `notifications_cache` (
                        `id` INTEGER NOT NULL,
                        `notifCode` TEXT,
                        `title` TEXT NOT NULL,
                        `body` TEXT,
                        `isRead` INTEGER NOT NULL,
                        `createdAt` TEXT,
                        `syncedAtMillis` INTEGER NOT NULL,
                        PRIMARY KEY(`id`)
                    )
                    """.trimIndent()
                )
            }
        }
        private val MIGRATION_2_3 = object : Migration(2, 3) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS `tasks_cache` (
                        `id` INTEGER NOT NULL,
                        `doCode` TEXT NOT NULL,
                        `trackingCode` TEXT,
                        `status` TEXT NOT NULL,
                        `customerCode` TEXT,
                        `doDate` TEXT,
                        `grandTotal` TEXT,
                        `syncedAtMillis` INTEGER NOT NULL,
                        PRIMARY KEY(`id`)
                    )
                    """.trimIndent()
                )
            }
        }
        private val MIGRATION_3_4 = object : Migration(3, 4) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE outbox_actions ADD COLUMN dedupKey TEXT NOT NULL DEFAULT ''")
                db.execSQL("ALTER TABLE outbox_actions ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0")
                db.execSQL("ALTER TABLE outbox_actions ADD COLUMN nextRetryAtMillis INTEGER")
                db.execSQL("UPDATE outbox_actions SET dedupKey = actionType || ':' || idempotencyKey")
                db.execSQL("CREATE UNIQUE INDEX IF NOT EXISTS index_outbox_actions_dedupKey ON outbox_actions(dedupKey)")
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS `sync_state` (
                        `key` TEXT NOT NULL,
                        `valueJson` TEXT NOT NULL,
                        `updatedAtMillis` INTEGER NOT NULL,
                        PRIMARY KEY(`key`)
                    )
                    """.trimIndent()
                )
            }
        }
        private val MIGRATION_4_5 = object : Migration(4, 5) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE outbox_actions ADD COLUMN lastErrorMessageMasked TEXT")
                db.execSQL("ALTER TABLE outbox_actions ADD COLUMN lastHttpStatus INTEGER")
                db.execSQL("ALTER TABLE outbox_actions ADD COLUMN inFlightAtMillis INTEGER")
                db.execSQL("ALTER TABLE outbox_actions ADD COLUMN leaseUntilMillis INTEGER")
                db.execSQL("ALTER TABLE outbox_actions ADD COLUMN lastWorkerRunId TEXT")
            }
        }

        fun get(context: Context): AppDatabase =
            instance ?: synchronized(this) {
                instance ?: Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "internalerp_mobile.db"
                ).addMigrations(MIGRATION_1_2, MIGRATION_2_3, MIGRATION_3_4, MIGRATION_4_5).build().also { instance = it }
            }
    }
}
