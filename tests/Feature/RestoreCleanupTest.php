<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Feature;

use Artryazanov\LaravelMysqlDriveBackup\Commands\RestoreMysqlFromDriveCommand;
use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class RestoreCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure we don't depend on a real mysql binary during tests by overriding command class later
    }

    private function makeTempDir(): string
    {
        $base = sys_get_temp_dir();
        $dir = $base.DIRECTORY_SEPARATOR.'restore-temp-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function bindTestableCommand(): void
    {
        // Bind the original command class to a testable subclass overriding importSqlFiles (no-op)
        $this->app->bind(RestoreMysqlFromDriveCommand::class, function ($app) {
            return new class($app->make(GoogleDriveService::class)) extends RestoreMysqlFromDriveCommand
            {
                protected function importSqlFiles(array $sqlFiles): void
                {
                    // No-op in tests: avoid calling external mysql
                    foreach ($sqlFiles as $f) {
                        // Assert files exist before cleanup path
                        if (! is_file($f)) {
                            throw new \RuntimeException('Expected SQL file missing in test: '.$f);
                        }
                    }
                }
            };
        });
    }

    public function test_cleans_up_gz_download_and_extracted_sql_by_default(): void
    {
        $restoreDir = $this->makeTempDir();
        $this->app['config']->set('drivebackup.restore_temp_dir', $restoreDir);

        // Mock GoogleDriveService to supply a .gz file and write gz-encoded content
        $mock = $this->createMock(GoogleDriveService::class);
        $mock->method('listBackupFiles')->willReturn([
            [
                'id' => 'file-1',
                'name' => 'dump.sql.gz',
                'mimeType' => 'application/gzip',
                'modifiedTime' => '2025-08-13T18:00:00Z',
            ],
        ]);
        $mock->method('downloadFileTo')->willReturnCallback(function (string $fileId, string $destPath) {
            $sql = "-- MySQL dump\nCREATE TABLE `t1` (id int);\nINSERT INTO `t1` VALUES (1);\n";
            $gzData = gzencode($sql, 1);
            file_put_contents($destPath, $gzData);
        });
        $this->app->instance(GoogleDriveService::class, $mock);

        $this->bindTestableCommand();

        // Run the command without --keep-temp
        $code = Artisan::call('backup:restore-mysql', [
            'mask' => '*.gz',
        ]);
        $this->assertSame(0, $code, 'Command should succeed');

        // Files should be cleaned: dump.sql.gz and extracted dump.sql
        $this->assertFileDoesNotExist($restoreDir.DIRECTORY_SEPARATOR.'dump.sql.gz');
        $this->assertFileDoesNotExist($restoreDir.DIRECTORY_SEPARATOR.'dump.sql');

        // Directory should remain
        $this->assertDirectoryExists($restoreDir);
    }

    public function test_cleans_up_sql_download_by_default(): void
    {
        $restoreDir = $this->makeTempDir();
        $this->app['config']->set('drivebackup.restore_temp_dir', $restoreDir);

        $mock = $this->createMock(GoogleDriveService::class);
        $mock->method('listBackupFiles')->willReturn([
            [
                'id' => 'file-2',
                'name' => 'backup.sql',
                'mimeType' => 'text/plain',
                'modifiedTime' => '2025-08-13T18:01:00Z',
            ],
        ]);
        $mock->method('downloadFileTo')->willReturnCallback(function (string $fileId, string $destPath) {
            $sql = "-- MySQL dump\nCREATE TABLE `t2` (id int);\n";
            file_put_contents($destPath, $sql);
        });
        $this->app->instance(GoogleDriveService::class, $mock);

        $this->bindTestableCommand();

        $code = Artisan::call('backup:restore-mysql', [
            'mask' => 'backup.sql',
        ]);
        $this->assertSame(0, $code);

        $this->assertFileDoesNotExist($restoreDir.DIRECTORY_SEPARATOR.'backup.sql');
        // Directory should remain
        $this->assertDirectoryExists($restoreDir);
    }

    public function test_keep_temp_option_skips_cleanup(): void
    {
        $restoreDir = $this->makeTempDir();
        $this->app['config']->set('drivebackup.restore_temp_dir', $restoreDir);

        $mock = $this->createMock(GoogleDriveService::class);
        $mock->method('listBackupFiles')->willReturn([
            [
                'id' => 'file-3',
                'name' => 'archive.sql.gz',
                'mimeType' => 'application/gzip',
                'modifiedTime' => '2025-08-13T18:02:00Z',
            ],
        ]);
        $mock->method('downloadFileTo')->willReturnCallback(function (string $fileId, string $destPath) {
            $sql = "-- MySQL dump\nCREATE TABLE `t3` (id int);\n";
            file_put_contents($destPath, gzencode($sql, 1));
        });
        $this->app->instance(GoogleDriveService::class, $mock);

        $this->bindTestableCommand();

        $code = Artisan::call('backup:restore-mysql', [
            'mask' => '*.gz',
            '--keep-temp' => true,
        ]);
        $this->assertSame(0, $code);

        // Files should remain when --keep-temp is used
        $this->assertFileExists($restoreDir.DIRECTORY_SEPARATOR.'archive.sql.gz');
        // Extracted file will exist because gunzipTo runs even with keep-temp
        $this->assertFileExists($restoreDir.DIRECTORY_SEPARATOR.'archive.sql');

        $this->assertDirectoryExists($restoreDir);
    }
}
