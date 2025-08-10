<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OAuth2 Google Client ID
    |--------------------------------------------------------------------------
    |
    | OAuth2 client identifier obtained in Google Cloud Console.
    |
    */
    'client_id' => env('GOOGLE_DRIVE_CLIENT_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | OAuth2 Google Client Secret
    |--------------------------------------------------------------------------
    |
    | OAuth2 client secret obtained in Google Cloud Console.
    |
    */
    'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | OAuth2 Redirect URI
    |--------------------------------------------------------------------------
    |
    | Redirect URI registered in Google Cloud Console (must match exactly).
    | Default points to local dev callback route.
    |
    */
    'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI', 'http://localhost:8000/google/drive/callback'),

    /*
    |--------------------------------------------------------------------------
    | Token file path
    |--------------------------------------------------------------------------
    |
    | Path to the file where OAuth2 token (including refresh_token) will be
    | stored and refreshed. Ensure the file is protected from unauthorized access.
    |
    */
    'token_file' => env('GOOGLE_DRIVE_TOKEN_PATH', storage_path('app/google_drive_token.json')),

    /*
    |--------------------------------------------------------------------------
    | Backup file name on Google Drive
    |--------------------------------------------------------------------------
    |
    | The name for the backup file as it will be saved on Google Drive. You may
    | include .sql or .gz extension if you use compression.
    |
    */
    'backup_file_name' => env('DB_BACKUP_NAME', 'mysql_backup_{timestamp}.sql'),

    /*
    |--------------------------------------------------------------------------
    | Temporary dump file path (local)
    |--------------------------------------------------------------------------
    |
    | Local path for temporary storage of the database dump before uploading to
    | Google Drive. Ensure the app has write permissions. Defaults to storage/app.
    |
    */
    'temp_file_path' => env('DB_BACKUP_TEMP_PATH', storage_path('app/mysql_backup_{timestamp}.sql')),

    /*
    |--------------------------------------------------------------------------
    | Compress dump before upload
    |--------------------------------------------------------------------------
    |
    | When true (default), the generated .sql dump will be gzip-compressed
    | before uploading to Google Drive. You can disable by setting the env
    | DB_BACKUP_COMPRESS=false. If enabled and your backup_file_name does not
    | end with .gz, the package will append .gz for the uploaded file name.
    |
    */
    'compress' => env('DB_BACKUP_COMPRESS', true),

    /*
    |--------------------------------------------------------------------------
    | Tables to exclude from backup
    |--------------------------------------------------------------------------
    |
    | List of table names that should be excluded from mysqldump. Provide plain
    | table names without a database prefix. For each listed table, the package
    | will append --ignore-table="{database}.{table}" to mysqldump. You may set
    | this via env DB_BACKUP_EXCLUDE_TABLES as a comma-separated list. Default empty.
    |
    */
    'exclude_tables' => array_filter(array_map('trim', explode(',', (string) env('DB_BACKUP_EXCLUDE_TABLES', '')))),
];
