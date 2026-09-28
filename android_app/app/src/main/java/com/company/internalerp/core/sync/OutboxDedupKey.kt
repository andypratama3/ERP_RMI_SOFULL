package com.company.internalerp.core.sync

object OutboxDedupKey {
    fun of(type: String, idempotencyKey: String): String {
        return "${type.trim()}:${idempotencyKey.trim()}"
    }
}
