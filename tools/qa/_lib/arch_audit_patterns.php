<?php
declare(strict_types=1);

/**
 * Architecture audit detection patterns.
 * Categories: STRONG (+30), MEDIUM (+15), WEAK (+5)
 */

return [
    'BROKER_STRONG' => [
        'docker' => '/rabbitmq|kafka|zookeeper|redpanda|nats|pulsar|activemq/i',
        'config' => '/rabbitmq\.conf|kafka\.properties|nats\.conf/i',
        'k8s' => '/kind:\s*Service.*rabbitmq|kafka|nats/i',
        'composer' => '/php-amqplib|enqueue\/amqp|rdkafka|nats-io|pulsar/i',
    ],
    'BROKER_MED' => [
        'env' => '/RABBITMQ_|AMQP_|KAFKA_|NATS_|PULSAR_|REDIS_STREAMS_/i',
        'supervisor' => '/queue|consumer|worker.*broker/i',
    ],
    'BROKER_WEAK' => [
        'docs' => '/Kafka|RabbitMQ|NATS|message broker/i',
    ],
    'BROKER_FALLBACK' => [
        'outbox' => '/outbox|queue.*table|queue_table/i',
        'cron' => '/cron.*queue|poll.*queue/i',
        'worker' => '/tools\/.*worker|worker\.php/i',
    ],
    'DATA_GOV_STRONG' => [
        'module' => '/data_quality|governance|mdm|validation_rules/i',
        'job' => '/quality_score|duplicate_detector|referential_check/i',
        'schema' => '/schema_registry|schema_registry/i',
    ],
    'DATA_GOV_MED' => [
        'validation' => '/input.*validation|validation.*lib/i',
        'dedupe' => '/dedupe|deduplicate/i',
        'constraints' => '/foreign key|unique constraint|constraint.*governance/i',
    ],
    'DATA_GOV_WEAK' => [
        'docs' => '/data governance|data quality/i',
    ],
    'CHANGE_GOV' => [
        'rfc' => '/rfc|RFC_|change_control|approval/i',
        'tools' => '/tools\/rfc|tools\/ops.*validate/i',
    ],
    'IAM_STRONG' => [
        'rbac' => '/roles.*table|permissions.*seed|require_permission|require_role/i',
        'mfa' => '/mfa|totp|2fa|two.factor|authenticator/i',
        'sso' => '/oidc|saml|ldap|openid|sso/i',
    ],
    'IAM_MED' => [
        'login' => '/require_login|requireLogin|require_auth/i',
        'guard' => '/guard|auth.*check|access.*check/i',
        'audit' => '/audit.*auth|auth.*event|login.*log/i',
    ],
    'IAM_WEAK' => [
        'docs' => '/RBAC|role.based|permission/i',
    ],
    'MONITORING_STRONG' => [
        'health' => '/health\.php|health_endpoint|/api/v1/health|/health/i',
        'alert' => '/alert_engine|alert.*rule|sla.*monitor/i',
        'metrics' => '/metrics|/metrics/i',
        'prometheus' => '/prometheus|grafana|pagerduty|slack.*notifier/i',
    ],
    'MONITORING_MED' => [
        'log' => '/jsonl|structured.*log|log.*json/i',
        'smoke' => '/smoke_http|readiness|preflight/i',
    ],
    'MONITORING_WEAK' => [
        'docs' => '/monitoring|observability/i',
    ],
    'BACKUP_STRONG' => [
        'backup' => '/backup_now|backup_manager|backup\.php|backup\.sh/i',
        'restore' => '/restore\.php|restore\.sh|restore.*dry/i',
        'manifest' => '/manifest\.json|checksum.*verif/i',
        'retention' => '/retention.*policy|backup.*retention/i',
    ],
    'BACKUP_MED' => [
        'dump' => '/mysqldump|pg_dump|dump.*sql/i',
        'storage' => '/storage\/backups|backups.*metadata/i',
    ],
    'BACKUP_WEAK' => [
        'docs' => '/backup|disaster recovery|DR/i',
    ],
];
