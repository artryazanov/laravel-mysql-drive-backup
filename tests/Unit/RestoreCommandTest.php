<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Unit;

use Artryazanov\LaravelMysqlDriveBackup\Commands\RestoreMysqlFromDriveCommand;
use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;
use ZipArchive;

class RestoreCommandTest extends TestCase
{
    protected function makeStubService(array $files = []): GoogleDriveService
    {
        return new class($files) extends GoogleDriveService {
            public function __construct(private array $files) {}
            public function listBackupFiles(?string $folderId = null): array { return $this->files; }
            public function downloadFileTo(string $fileId, string $destPath): void {}
        };
    }

    protected function makeCommand(GoogleDriveService $service): TestRestoreCommand
    {
        return new TestRestoreCommand($service);
    }

    public function test_find_latest_drive_file_by_mask_returns_newest(): void
    {
        $files = [
            ['id' => '2', 'name' => 'backup-new.sql', 'mimeType' => '', 'modifiedTime' => '2024-02-02T00:00:00Z'],
            ['id' => '1', 'name' => 'backup-old.sql', 'mimeType' => '', 'modifiedTime' => '2024-01-01T00:00:00Z'],
            ['id' => '3', 'name' => 'other.sql', 'mimeType' => '', 'modifiedTime' => '2024-03-01T00:00:00Z'],
        ];
        $cmd = $this->makeCommand($this->makeStubService($files));

        $res = $cmd->findLatestDriveFileByMaskPublic('backup-*.sql');

        $this->assertNotNull($res);
        $this->assertSame('2', $res['id']);
    }

    public function test_prepare_sql_files_handles_gz(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'restore_test_'.uniqid();
        mkdir($dir);
        $srcSql = $dir.DIRECTORY_SEPARATOR.'sample.sql';
        file_put_contents($srcSql, "SELECT 1;\n");
        $gzPath = $srcSql.'.gz';
        $gz = gzopen($gzPath, 'wb9');
        gzwrite($gz, file_get_contents($srcSql));
        gzclose($gz);

        $cmd = $this->makeCommand($this->makeStubService());
        $out = $cmd->prepareSqlFilesPublic($gzPath, $dir);

        $this->assertCount(1, $out);
        $this->assertFileExists($out[0]);
        $this->assertSame("SELECT 1;\n", file_get_contents($out[0]));

        @unlink($srcSql);
        @unlink($gzPath);
        @unlink($out[0]);
        @rmdir($dir);
    }

    public function test_prepare_sql_files_handles_zip(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'restore_test_'.uniqid();
        mkdir($dir);
        $zipPath = $dir.DIRECTORY_SEPARATOR.'bundle.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('a.sql', 'A');
        $zip->addFromString('b.sql', 'B');
        $zip->close();

        $cmd = $this->makeCommand($this->makeStubService());
        $out = $cmd->prepareSqlFilesPublic($zipPath, $dir);

        $this->assertCount(2, $out);
        $this->assertSame(['a.sql', 'b.sql'], array_map('basename', $out));

        foreach ($out as $f) { @unlink($f); }
        @unlink($zipPath);
        @rmdir($dir);
    }

    public function test_filter_sql_by_tables_single_file_only(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'restore_test_'.uniqid();
        mkdir($dir);
        $src = $dir.DIRECTORY_SEPARATOR.'all.sql';
        $sql = "-- Table structure for table `users`\n".
            "DROP TABLE IF EXISTS `users`;\n".
            "CREATE TABLE `users` (id int);\n".
            "LOCK TABLES `users` WRITE;\n".
            "INSERT INTO `users` VALUES (1);\n".
            "UNLOCK TABLES;\n".
            "-- Table structure for table `posts`\n".
            "DROP TABLE IF EXISTS `posts`;\n".
            "CREATE TABLE `posts` (id int);\n".
            "LOCK TABLES `posts` WRITE;\n".
            "INSERT INTO `posts` VALUES (1);\n".
            "UNLOCK TABLES;\n";
        file_put_contents($src, $sql);

        $cmd = $this->makeCommand($this->makeStubService());
        $out = $cmd->filterSqlByTablesPublic([$src], ['users'], [], $dir);

        $this->assertCount(1, $out);
        $filtered = file_get_contents($out[0]);
        $this->assertStringContainsString('Table structure for table `users`', $filtered);
        $this->assertStringNotContainsString('Table structure for table `posts`', $filtered);

        @unlink($src);
        @unlink($out[0]);
        @rmdir($dir);
    }

    public function test_filter_sql_by_tables_multi_file_except(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'restore_test_'.uniqid();
        mkdir($dir);
        $f1 = $dir.DIRECTORY_SEPARATOR.'users.sql';
        $f2 = $dir.DIRECTORY_SEPARATOR.'logs_2023.sql';
        file_put_contents($f1, '');
        file_put_contents($f2, '');

        $cmd = $this->makeCommand($this->makeStubService());
        $out = $cmd->filterSqlByTablesPublic([$f1, $f2], [], ['logs_*'], $dir);

        $this->assertSame([$f1], $out);

        @unlink($f1);
        @unlink($f2);
        @rmdir($dir);
    }
}

class TestRestoreCommand extends RestoreMysqlFromDriveCommand
{
    public function __construct(GoogleDriveService $drive)
    {
        parent::__construct($drive);
    }

    public function findLatestDriveFileByMaskPublic(string $mask): ?array
    {
        return $this->findLatestDriveFileByMask($mask);
    }

    public function prepareSqlFilesPublic(string $localPath, string $restoreDir): array
    {
        return $this->prepareSqlFiles($localPath, $restoreDir);
    }

    public function filterSqlByTablesPublic(array $sqlFiles, array $only, array $except, string $restoreDir): array
    {
        return $this->filterSqlByTables($sqlFiles, $only, $except, $restoreDir);
    }
}

