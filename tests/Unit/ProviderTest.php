<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Unit;

use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ProviderTest extends TestCase
{
    public function test_config_is_merged_and_defaults_available(): void
    {
        $this->assertSame('mysql_backup_{timestamp}.sql', config('drivebackup.backup_file_name'));
        $this->assertIsString(config('drivebackup.client_id'));
        $this->assertIsString(config('drivebackup.client_secret'));
        $this->assertIsString(config('drivebackup.token_file'));
        $this->assertIsString(config('drivebackup.temp_file_path'));
        $this->assertTrue(filter_var(config('drivebackup.compress'), FILTER_VALIDATE_BOOL));
        $this->assertIsArray(config('drivebackup.exclude_tables'));
    }

    public function test_commands_are_registered_in_artisan(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('backup:mysql-to-drive', $commands);
        $this->assertArrayHasKey('backup:authorize-drive', $commands);
    }
}
