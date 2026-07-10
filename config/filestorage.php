<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Upload File Size
    |--------------------------------------------------------------------------
    | The maximum allowed size for uploaded files, in bytes.
    | Env: MAX_UPLOAD_BYTES — must be a positive integer.
    | Default: 10 MB (10,485,760 bytes).
    */
    'max_upload_bytes' => (int) env('MAX_UPLOAD_BYTES', 10_485_760),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    | Only these content-detected MIME types are accepted during upload.
    | This list is not environment-configurable by design; change it here.
    */
    'allowed_mime_types' => [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Period (hours)
    |--------------------------------------------------------------------------
    | Number of hours a file is retained before the scheduler deletes it.
    | Env: RETENTION_HOURS — must be a positive integer.
    | Default: 24 hours.
    */
    'retention_hours' => (int) env('RETENTION_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Upload Rate Limit
    |--------------------------------------------------------------------------
    | Maximum number of upload requests per IP per minute.
    | Env: RATE_LIMIT_UPLOAD — must be a positive integer.
    | Default: 20 requests/minute.
    */
    'rate_limit_upload' => (int) env('RATE_LIMIT_UPLOAD', 20),

    /*
    |--------------------------------------------------------------------------
    | Delete Rate Limit
    |--------------------------------------------------------------------------
    | Maximum number of delete requests per IP per minute.
    | Env: RATE_LIMIT_DELETE — must be a positive integer.
    | Default: 30 requests/minute.
    */
    'rate_limit_delete' => (int) env('RATE_LIMIT_DELETE', 30),

    /*
    |--------------------------------------------------------------------------
    | Download Rate Limit
    |--------------------------------------------------------------------------
    | Maximum number of download requests per IP per minute.
    | Env: RATE_LIMIT_DOWNLOAD — must be a positive integer.
    | Default: 60 requests/minute.
    */
    'rate_limit_download' => (int) env('RATE_LIMIT_DOWNLOAD', 60),

    /*
    |--------------------------------------------------------------------------
    | Pagination Page Size
    |--------------------------------------------------------------------------
    | Number of file records displayed per page on the CRUD page.
    | Env: PAGINATION_PER_PAGE — must be a positive integer.
    | Default: 15 records/page.
    */
    'pagination_per_page' => (int) env('PAGINATION_PER_PAGE', 15),
];
