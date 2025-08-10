<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Unit;

use Artryazanov\LaravelMysqlDriveBackup\Services\DumpService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;

class DumpServiceTest extends TestCase
{
    public function test_dump_creates_directory_and_file(): void
    {
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup_pkg_'.uniqid();
        $dumpPath = $tempDir.DIRECTORY_SEPARATOR.'dump.sql';

        $service = new class extends DumpService
        {
            protected function runCommand(string $command, array &$output, int &$resultCode): void
            {
                $resultCode = 0;
                // Extract result-file path from the command and create a dummy file
                if (preg_match('/--result-file=([^\s]+)/', $command, $m)) {
                    $path = trim($m[1], "'\"");
                    @mkdir(dirname($path), 0777, true);
                    file_put_contents($path, "-- dummy dump --\n");
                }
            }
        };

        $service->dump($dumpPath);
        $this->assertFileExists($dumpPath);

        @unlink($dumpPath);
        @rmdir($tempDir);
    }

    public function test_dump_throws_if_not_mysql_driver(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        $service = new DumpService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not MySQL');

        $service->dump(sys_get_temp_dir().DIRECTORY_SEPARATOR.'x.sql');
    }

    public function test_dump_throws_on_nonzero_exit(): void
    {
        $dumpPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'x.sql';

        $service = new class extends DumpService
        {
            protected function runCommand(string $command, array &$output, int &$resultCode): void
            {
                $resultCode = 2;
                $output[] = 'simulated error';
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mysqldump failed');

        $service->dump($dumpPath);
    }

    public function test_dump_adds_ignore_table_arguments_when_configured(): void
    {
        // Configure two tables to exclude
        config(['drivebackup.exclude_tables' => ['jobs', 'failed_jobs']]);

        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup_pkg_'.uniqid();
        $dumpPath = $tempDir.DIRECTORY_SEPARATOR.'dump.sql';

        $service = new class extends DumpService
        {
            public string $lastCommand = '';

            protected function runCommand(string $command, array &$output, int &$resultCode): void
            {
                $this->lastCommand = $command;
                $resultCode = 0;
                if (preg_match('/--result-file=([^\s]+)/', $command, $m)) {
                    $path = trim($m[1], "'\"");
                    @mkdir(dirname($path), 0777, true);
                    file_put_contents($path, "-- dummy dump --\n");
                }
            }
        };

        // Run dump
        $service->dump($dumpPath);

        // Ensure ignore-table arguments are present for the configured DB (testdb from TestCase)
        $this->assertStringContainsString('ignore-table=', $service->lastCommand);
        $this->assertStringContainsString('testdb.jobs', $service->lastCommand);
        $this->assertStringContainsString('testdb.failed_jobs', $service->lastCommand);

        @unlink($dumpPath);
        @rmdir($tempDir);
    }
}
