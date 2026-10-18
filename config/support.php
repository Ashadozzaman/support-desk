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
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'cache_ttl' => (int) env('SUPPORT_DASHBOARD_CACHE_TTL', 60),
    ],

];
