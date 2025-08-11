<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Unit;

use Artryazanov\LaravelMysqlDriveBackup\Services\DumpService;
use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;

/**
 * @internal
 */
class BackupMysqlToDriveCommandTest extends TestCase
{
    public function test_returns_error_when_token_missing(): void
    {
        $tokenPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing_token_'.uniqid().'.json';
        config(['drivebackup.token_file' => $tokenPath]);

        $this->artisan('backup:mysql-to-drive')
            ->assertExitCode(1)
            ->expectsOutputToContain('OAuth2 token not found');
    }

    public function test_creates_dump_and_uploads_to_drive_with_compression(): void
    {
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup_cmd_'.uniqid();
        mkdir($tempDir);
        $tokenPath = $tempDir.DIRECTORY_SEPARATOR.'token.json';
        file_put_contents($tokenPath, '{}');

        config([
            'drivebackup.token_file' => $tokenPath,
            'drivebackup.temp_file_path' => $tempDir.DIRECTORY_SEPARATOR.'dump_{timestamp}.sql',
            'drivebackup.backup_file_name' => 'backup_{timestamp}.sql',
            'drivebackup.compress' => true,
        ]);

        $dumpService = new class extends DumpService {
            public function __construct() {}
            public function createBackup(string $path): void
            {
                file_put_contents($path, 'dummy');
            }
        };
        $driveService = new class extends GoogleDriveService {
            public array $uploaded = [];
            public function __construct() {}
            public function uploadFile(string $path, string $name): void
            {
                $this->uploaded = [$path, $name];
                if (! is_file($path)) {
                    throw new \RuntimeException('File not found: '.$path);
                }
            }
        };

        $this->app->instance(DumpService::class, $dumpService);
        $this->app->instance(GoogleDriveService::class, $driveService);

        $this->artisan('backup:mysql-to-drive')
            ->assertExitCode(0)
            ->expectsOutputToContain('Backup uploaded successfully');

        [$uploadedPath, $uploadedName] = $driveService->uploaded;
        $this->assertStringEndsWith('.gz', $uploadedPath);
        $this->assertStringEndsWith('.gz', $uploadedName);
        $this->assertFileDoesNotExist($uploadedPath);
        $this->assertFileDoesNotExist(str_replace('.gz', '', $uploadedPath));

        @unlink($tokenPath);
        @rmdir($tempDir);
    }
}
