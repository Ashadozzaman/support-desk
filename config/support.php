<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SLA
    |--------------------------------------------------------------------------
    |
    | Business-hours timezone used by BusinessHoursCalculator; all stored
    | timestamps stay UTC. Default warning threshold is the percentage of
    | the SLA window consumed before a ticket is flagged "approaching".
    */
    'sla' => [
        'timezone' => env('SUPPORT_SLA_TIMEZONE', 'Asia/Dhaka'),
        'warning_threshold_percent' => (int) env('SUPPORT_SLA_WARNING_THRESHOLD_PERCENT', 80),
    ],

    /*
    |--------------------------------------------------------------------------
    | Assignment
    |--------------------------------------------------------------------------
    |
    | Strategy bound in RepositoryServiceProvider; 'least_workload' (default)
    | or 'round_robin'.
    */
    'assignment' => [
        'strategy' => env('SUPPORT_ASSIGNMENT_STRATEGY', 'least_workload'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        'max_files' => (int) env('SUPPORT_UPLOADS_MAX_FILES', 5),
        'max_kb' => (int) env('SUPPORT_UPLOADS_MAX_KB', 5120),
        'mimes' => ['jpg', 'jpeg', 'png', 'pdf', 'txt', 'csv', 'docx', 'xlsx'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Keyword search on the ticket list uses MySQL FULLTEXT MATCH ... AGAINST
    | by default; flip to a LIKE fallback for engines without FULLTEXT
    | support (ARCHITECTURE.md §12).
    */
    'search' => [
        'fulltext' => (bool) env('SUPPORT_SEARCH_FULLTEXT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | CSV import
    |--------------------------------------------------------------------------
    */
    'import' => [
        'chunk_size' => (int) env('SUPPORT_IMPORT_CHUNK_SIZE', 500),
        'max_kb' => (int) env('SUPPORT_IMPORT_MAX_KB', 20480),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'cache_ttl' => (int) env('SUPPORT_DASHBOARD_CACHE_TTL', 60),

        // By-agent breakdown (ARCHITECTURE.md §14): an active ticket is
        // "stuck" once it has been assigned at least this many days
        // without reaching resolved/closed.
        'stuck_after_days' => (int) env('SUPPORT_DASHBOARD_STUCK_AFTER_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | Ticket exports up to export_sync_limit rows stream straight to the
    | browser; larger ones are generated on the `exports` queue and kept for
    | export_ttl_days before model:prune deletes them.
    */
    'reports' => [
        'export_sync_limit' => (int) env('SUPPORT_REPORTS_EXPORT_SYNC_LIMIT', 50000),
        'export_ttl_days' => (int) env('SUPPORT_REPORTS_EXPORT_TTL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    |
    | slow_query_ms: a single query slower than this is written to the
    | `performance` log channel (SQL only, never bindings). Some report and
    | dashboard-warm queries legitimately exceed 500 ms at ~1M tickets, so
    | expect those to show up; raise this if they are noise.
    | failed_job_alert_minutes: at most one admin alert per job class per window.
    | Health checks themselves: AppServiceProvider::configureHealthChecks().
    */
    'monitoring' => [
        'slow_query_ms' => (int) env('SUPPORT_SLOW_QUERY_MS', 500),
        'failed_job_alert_minutes' => (int) env('SUPPORT_FAILED_JOB_ALERT_MINUTES', 10),

        // GET /health requires this in the X-Health-Secret header; empty =
        // endpoint disabled (401 for everyone).
        'health_secret' => env('HEALTH_SECRET'),

        // Queues watched by QueueCheck/QueueBacklogCheck (`imports` excluded:
        // its own worker may legitimately be busy for an hour).
        'queues' => ['sla', 'default', 'notifications', 'broadcasts', 'exports', 'ai'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log (Step 19c)
    |--------------------------------------------------------------------------
    |
    | Retention per log, enforced daily by `activitylog:clean <log> --days=`.
    | auth rows carry IPs/user agents (personal data) and grow fastest, so
    | they go first; admin changes are few and worth keeping longer. Ticket
    | activity (ticket_activities) is never deleted — it is each ticket's
    | timeline and feeds the reports.
    | page_default_days: the audit page opens on this many days (incl. today).
    */
    'audit' => [
        'auth_retention_days' => (int) env('AUDIT_AUTH_RETENTION_DAYS', 180),
        'admin_retention_days' => (int) env('AUDIT_ADMIN_RETENTION_DAYS', 1095),
        'page_default_days' => (int) env('AUDIT_PAGE_DEFAULT_DAYS', 30),
    ],

];
