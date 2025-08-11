<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Unit;

use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * @internal
 */
class CleanupDriveBackupsCommandTest extends TestCase
{
    public function test_removes_old_backups(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-20 00:00:00'));

        config(['drivebackup.backup_file_name' => 'backup_{timestamp}.sql']);

        $service = new class extends GoogleDriveService
        {
            public array $files = [];

            public array $deleted = [];

            public function __construct() {}

            public function listBackupFiles(?string $folderId = null): array
            {
                return $this->files;
            }

            public function deleteFile(string $fileId): void
            {
                $this->deleted[] = $fileId;
            }
        };

        $service->files = [
            ['id' => '1', 'name' => 'backup_20240120-100000.sql.gz', 'modifiedTime' => '2024-01-20T10:00:00+00:00'],
            ['id' => '2', 'name' => 'backup_20240120-150000.sql.gz', 'modifiedTime' => '2024-01-20T15:00:00+00:00'],
            ['id' => '3', 'name' => 'backup_20240119-120000.sql.gz', 'modifiedTime' => '2024-01-19T12:00:00+00:00'],
            ['id' => '4', 'name' => 'backup_20240113-120000.sql.gz', 'modifiedTime' => '2024-01-13T12:00:00+00:00'],
            ['id' => '5', 'name' => 'backup_20240107-120000.sql.gz', 'modifiedTime' => '2024-01-07T12:00:00+00:00'],
            ['id' => '6', 'name' => 'backup_20240105-120000.sql.gz', 'modifiedTime' => '2024-01-05T12:00:00+00:00'],
            ['id' => '7', 'name' => 'backup_20231215-120000.sql.gz', 'modifiedTime' => '2023-12-15T12:00:00+00:00'],
            ['id' => '8', 'name' => 'backup_20231210-120000.sql.gz', 'modifiedTime' => '2023-12-10T12:00:00+00:00'],
            ['id' => '9', 'name' => 'backup_20231120-120000.sql.gz', 'modifiedTime' => '2023-11-20T12:00:00+00:00'],
            ['id' => '10', 'name' => 'backup_20231110-120000.sql.gz', 'modifiedTime' => '2023-11-10T12:00:00+00:00'],
            ['id' => '11', 'name' => 'backup_20220801-120000.sql.gz', 'modifiedTime' => '2022-08-01T12:00:00+00:00'],
            ['id' => '12', 'name' => 'backup_20220501-120000.sql.gz', 'modifiedTime' => '2022-05-01T12:00:00+00:00'],
            ['id' => 'x', 'name' => 'otherfile.sql.gz', 'modifiedTime' => '2024-01-20T15:00:00+00:00'],
        ];

        $this->app->instance(GoogleDriveService::class, $service);

        $this->artisan('backup:cleanup-drive')
            ->assertExitCode(0)
            ->expectsOutputToContain('Cleanup completed.');

        $this->assertSame(['1', '6', '10', '12'], $service->deleted);
    }
}
