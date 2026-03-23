# ☁️ Laravel MySQL Drive Backup (OAuth2)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/artryazanov/laravel-mysql-drive-backup.svg?style=flat-square)](https://packagist.org/packages/artryazanov/laravel-mysql-drive-backup)
[![Tests](https://img.shields.io/github/actions/workflow/status/artryazanov/laravel-mysql-drive-backup/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/artryazanov/laravel-mysql-drive-backup/actions/workflows/tests.yml)
[![Lint](https://img.shields.io/github/actions/workflow/status/artryazanov/laravel-mysql-drive-backup/lint.yml?branch=main&label=lint&style=flat-square)](https://github.com/artryazanov/laravel-mysql-drive-backup/actions/workflows/lint.yml)
[![Codecov](https://img.shields.io/codecov/c/github/artryazanov/laravel-mysql-drive-backup.svg?style=flat-square)](https://codecov.io/gh/artryazanov/laravel-mysql-drive-backup)
[![Total Downloads](https://img.shields.io/packagist/dt/artryazanov/laravel-mysql-drive-backup.svg?style=flat-square)](https://packagist.org/packages/artryazanov/laravel-mysql-drive-backup)
[![License](https://img.shields.io/github/license/artryazanov/laravel-mysql-drive-backup.svg?style=flat-square)](https://github.com/artryazanov/laravel-mysql-drive-backup/blob/main/LICENSE)

Laravel 10-12 package to back up a MySQL database and upload the dump to Google Drive using OAuth2 (user consent). Provides Artisan commands for authorization, creating backups, and restoring them from Drive (supports .sql, .gz, .zip and wildcard masks). Suitable for manual runs and for scheduling via Laravel Scheduler.

## 📋 Requirements
- PHP 8.2+
- Laravel 10.x–12.x
- MySQL client utilities installed and available in PATH:
  - mysqldump (for backup)
  - mysql (for restore)
- PHP extensions: zip, zlib
- Google OAuth2 credentials and configured redirect URI

## 📦 Installation

You can install the package via composer:

```bash
composer require artryazanov/laravel-mysql-drive-backup
```

Laravel auto-discovers the service provider. No manual registration is needed.

## ⚙️ Configuration

Publish the configuration to customize defaults:

```bash
php artisan vendor:publish --tag=config
```

Configuration file: `config/drivebackup.php`

- client_id: Google OAuth2 Client ID (env GOOGLE_DRIVE_CLIENT_ID)
- client_secret: Google OAuth2 Client Secret (env GOOGLE_DRIVE_CLIENT_SECRET)
- redirect_uri: Redirect URI registered in Google Cloud Console (env GOOGLE_DRIVE_REDIRECT_URI, default http://localhost:8000/google/drive/callback)
- token_file: Path to the token JSON file (env GOOGLE_DRIVE_TOKEN_PATH, default storage/app/google_drive_token.json)
- drive_backup_folder_id: Optional Drive folder ID where backups are stored (env GOOGLE_DRIVE_BACKUP_FOLDER_ID)
- backup_file_name: File name to use on Google Drive (env DB_BACKUP_NAME, default mysql_backup_{timestamp}.sql). You can include {timestamp} placeholder which is replaced as YYYYMMDD-HHMMSS.
- temp_file_path: Local temporary dump path (env DB_BACKUP_TEMP_PATH, default storage/app/mysql_backup_{timestamp}.sql). You can include {timestamp} placeholder which is replaced as YYYYMMDD-HHMMSS.
- restore_temp_dir: Temporary directory for downloaded archives during restore (env DB_RESTORE_TEMP_DIR, default storage/app/drive-restore-temp)
- compress: When true (default), gzip-compress the .sql dump before upload (env DB_BACKUP_COMPRESS, default true). If enabled and backup_file_name does not end with .gz, the uploaded name will have .gz appended.
- exclude_tables: Array of table names to exclude from backup. Set via env DB_BACKUP_EXCLUDE_TABLES as a comma-separated list (e.g., "jobs,failed_jobs,sessions"). For each listed table, mysqldump will receive --ignore-table="{database}.{table}".

## 🔐 Google Drive OAuth2 Authorization

### 1. Obtaining Google Drive API Credentials

To use this package, you need to create an OAuth2 application in the Google Cloud Console and obtain a Client ID and Client Secret.

1. Go to the [Google Cloud Console](https://console.cloud.google.com/).
2. Create a new project or select an existing one.
3. **Enable the Google Drive API**:
   - Navigate to **APIs & Services** > **Library**.
   - Search for **Google Drive API** and click **Enable**.
4. **Configure the OAuth Consent Screen**:
   - Navigate to **APIs & Services** > **OAuth consent screen**.
   - Choose **External** (or Internal if using a Google Workspace) and click **Create**.
   - Fill in the required primary fields (App name, User support email, Developer contact information) and click Save and Continue through the steps.
   - *Note: If your application publishing status is "Testing", you MUST add the Google account you will use for backups to the **Test users** list. Otherwise, authorization will fail.*
5. **Create Credentials**:
   - Navigate to **APIs & Services** > **Credentials**.
   - Click **Create Credentials** > **OAuth client ID**.
   - Select **Web application** as the Application type.
   - Name your OAuth client (e.g., "Laravel Drive Backup").
   - Under **Authorized redirect URIs**, add your application's callback URL. This must exactly match your `GOOGLE_DRIVE_REDIRECT_URI` value (e.g., `http://localhost:8000/google/drive/callback` for local development or `https://your-domain.com/google/drive/callback` for production).
   - Click **Create**.
6. A dialog will appear with your **Client ID** and **Client Secret**.

### 2. Environment Configuration

Add the obtained credentials and redirect URI to your `.env` file:

```env
GOOGLE_DRIVE_CLIENT_ID=your-client-id...
GOOGLE_DRIVE_CLIENT_SECRET=your-client-secret...
GOOGLE_DRIVE_REDIRECT_URI=http://localhost:8000/google/drive/callback
```

### 3. Application Setup

Publish config and clear cache if needed:

```bash
php artisan vendor:publish --tag=config
php artisan cache:clear && php artisan config:clear
```

4. Run the authorization command:

```bash
php artisan backup:authorize-drive
```

Open the link, approve access; Google will redirect to your redirect_uri (/google/drive/callback). The package callback stores the token automatically. If you prefer to enter the code manually, confirm the prompt in the console and paste the value of the `code` parameter from the redirected URL.

## 💻 Usage

Run the backup command:

```bash
php artisan backup:mysql-to-drive
```

Restore a backup from Google Drive:

```bash
php artisan backup:restore-mysql "backup-*.sql.gz"
```

You may restrict tables during restore:

```bash
php artisan backup:restore-mysql "backup-*.zip" --only=users,orders
php artisan backup:restore-mysql "nightly-*.sql" --except=log_*
```

### Restore options and behavior
- mask: File name or wildcard mask to search on Google Drive. The latest file by modifiedTime matching the mask is selected. Examples: `backup-*.sql`, `*.sql.gz`, `*.zip`.
- Supported formats: `.sql`, `.gz` (gzip of a single .sql), `.zip` (one or more .sql files inside).
- --only: Comma-separated table names or wildcard masks to include (e.g., `users,orders`, `log_*`).
- --except: Comma-separated table names or wildcard masks to exclude.
- --keep-temp: Keep downloaded/extracted temporary files and skip cleanup at the end.

Cleanup by default: After a successful restore, the command removes only artifacts created during the current run in the restore temp directory (the directory itself remains). For example:
- When restoring a `.gz` dump: removes the downloaded `.gz`, the extracted `.sql`, and the filtered variant if created.
- When restoring a `.sql` dump: removes the downloaded `.sql` and its filtered variant if created.
- For `.zip`: extracted .sql files are intentionally preserved because their names cannot be reliably inferred from the archive name. Cleanup removes the downloaded `.zip` and conservative `filtered-*` variants only; manage extracted files yourself if needed. Use `--keep-temp` to skip any cleanup entirely.

Remove outdated backups from Google Drive according to the retention policy:

```bash
php artisan backup:cleanup-drive
```

Backup command behaviour:
1. The package creates a MySQL dump of the default connection (must be MySQL).
2. The dump file is uploaded to Google Drive using OAuth2.
3. On success, the local dump file is removed.

## 💾 Large dumps and memory usage

This package handles large backup files efficiently by streaming data instead of loading it into memory:
- Downloads from Google Drive are streamed to disk in chunks.
- .gz archives are decompressed with gzread() in chunks.
- .zip archives are extracted via ZipArchive streams and written in chunks.
- SQL filtering is performed line-by-line; allowed table blocks are written directly to the output file without buffering entire tables in memory.
- MySQL import uses passthru, avoiding accumulation of child process output in PHP memory.

This design keeps peak RAM usage low even for multi‑gigabyte dumps. Ensure there is enough free disk space in restore_temp_dir for temporary files during restore.

## ⏰ Scheduling

Add this to your `app/Console/Kernel.php` schedule method:

```php
$schedule->command('backup:mysql-to-drive')->dailyAt('03:00');
$schedule->command('backup:cleanup-drive')->dailyAt('04:00');
```

## 🛠️ Troubleshooting

- mysqldump not found: Ensure `mysqldump` is installed and available in PATH on the server.
- The default database connection is not MySQL: Configure Laravel DB to use a MySQL connection as default.
- Token file not found: Run `php artisan backup:authorize-drive` first.

## 🛡️ Security

Protect your token file and client secret. Do not commit secrets to version control.

## 📄 License

This package is released under the MIT License. See [LICENSE](LICENSE) for details.
