<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Unit;

use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;

class GoogleDriveServiceTest extends TestCase
{
    public function test_upload_throws_when_token_missing(): void
    {
        // Ensure token path points to a non-existing file
        $tokenPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nonexistent-token.json';
        config(['drivebackup.token_file' => $tokenPath]);

        $service = new GoogleDriveService('client-id', 'client-secret', $tokenPath);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/token file not found/i');

        $service->uploadFile(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dummy.sql', 'dummy.sql');
    }
}
