<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Unit;

use Artryazanov\LaravelMysqlDriveBackup\Commands\RestoreMysqlFromDriveCommand;
use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;

/**
 * @internal
 */
class RestoreMysqlFromDriveCommandTest extends TestCase
{
    protected function makeFakeDrive(array $files, callable $downloader): GoogleDriveService
    {
        return new class($files, $downloader) extends GoogleDriveService {
            public array $files;
            public $downloader;
            public function __construct($files, $downloader)
            {
                // do not call parent constructor
                $this->files = $files;
                $this->downloader = $downloader;
            }
            public function listBackupFiles(?string $folderId = null): array
            {
                return $this->files;
            }
            public function downloadFileTo(string $fileId, string $destPath): void
            {
                ($this->downloader)($fileId, $destPath);
            }
        };
    }

    public function test_returns_error_when_file_not_found(): void
    {
        $drive = $this->makeFakeDrive([], function () {});
        $this->app->instance(GoogleDriveService::class, $drive);
        $this->artisan('backup:restore-mysql', ['mask' => 'backup-*.sql'])
            ->assertExitCode(1)
            ->expectsOutputToContain("not found on Google Drive");
    }

    public function test_restores_from_zip_with_only_option(): void
    {
        $files = [[
            'id' => '1',
            'name' => 'backup.zip',
            'modifiedTime' => '2024-01-01T00:00:00Z',
        ]];
        $drive = $this->makeFakeDrive($files, function ($id, $dest) {
            $zip = new \ZipArchive();
            $zip->open($dest, \ZipArchive::CREATE);
            $zip->addFromString('users.sql', "-- users table\nCREATE TABLE `users` ();\n");
            $zip->addFromString('posts.sql', "-- posts table\nCREATE TABLE `posts` ();\n");
            $zip->close();
        });
        $this->app->instance(GoogleDriveService::class, $drive);

        $restoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_test_' . uniqid();
        config(['drivebackup.restore_temp_dir' => $restoreDir]);

        $command = new class($drive) extends RestoreMysqlFromDriveCommand {
            public array $imported = [];
            public function __construct($drive)
            {
                parent::__construct($drive);
            }
            protected function importSqlFiles(array $sqlFiles): void
            {
                $this->imported = $sqlFiles;
            }
        };
        $this->app->instance(RestoreMysqlFromDriveCommand::class, $command);

        $this->artisan('backup:restore-mysql', ['mask' => '*.zip', '--only' => 'users'])
            ->assertExitCode(0)
            ->expectsOutput('Restore completed successfully.');

        $this->assertCount(1, $command->imported);
        $this->assertSame('users.sql', basename($command->imported[0]));
        $this->assertFileExists($command->imported[0]);

        // cleanup
        @unlink($command->imported[0]);
        @unlink($restoreDir . DIRECTORY_SEPARATOR . 'backup.zip');
        @rmdir($restoreDir);
    }
}
