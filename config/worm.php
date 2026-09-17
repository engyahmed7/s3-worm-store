<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Object Lock
    |--------------------------------------------------------------------------
    |
    | MinIO WORM uses S3 Object Lock. GOVERNANCE blocks deletes unless the
    | caller has BypassGovernanceRetention. COMPLIANCE cannot be bypassed
    | by anyone, including the root user, until the retention period ends.
    |
    */

    'lock_mode' => env('WORM_LOCK_MODE', 'GOVERNANCE'),

    'retention_days' => (int) env('WORM_RETENTION_DAYS', 1),

    'upload_max_kilobytes' => (int) env('WORM_UPLOAD_MAX_KILOBYTES', 10240),

    'allowed_mimes' => [
        'pdf',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'txt',
        'csv',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'zip',
    ],

];
