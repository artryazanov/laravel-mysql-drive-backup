<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Commands;

use Illuminate\Console\Command;
use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Services\DumpService;
use Exception;

class BackupMysqlToDriveCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'backup:mysql-to-drive';

    /**
     * The console command description.
     */
    protected $description = 'Create a MySQL dump and upload it to Google Drive (OAuth2)';

    private GoogleDriveService $driveService;
    private DumpService $dumpService;

    public function __construct(GoogleDriveService $driveService, DumpService $dumpService)
    {
        parent::__construct();
        $this->driveService = $driveService;
        $this->dumpService = $dumpService;
    }

    public function handle(): int
    {
        $tokenPath = (string) config('drivebackup.token_file');
        if (!is_file($tokenPath)) {
            $this->error('OAuth2 token not found. Run backup:authorize-drive first.');
            return 1;
        }

        $timestamp = date('Ymd-His');
        $dumpPath = str_replace('{timestamp}', $timestamp, (string) config('drivebackup.temp_file_path'));
        $fileName = str_replace('{timestamp}', $timestamp, (string) config('drivebackup.backup_file_name'));
        $compress = filter_var(config('drivebackup.compress', true), FILTER_VALIDATE_BOOL);

        $this->info('Creating MySQL dump...');
        try {
            $this->dumpService->createBackup($dumpPath);
        } catch (Exception $e) {
            $this->error('Error creating dump: ' . $e->getMessage());
            return 1;
        }
        $this->info('Dump created at: ' . $dumpPath);

        $uploadPath = $dumpPath;
        $uploadName = $fileName;
        $gzPath = $dumpPath . '.gz';

        if ($compress) {
            $this->info('Compressing dump with gzip...');
            try {
                $this->gzipFile($dumpPath, $gzPath);
            } catch (Exception $e) {
                $this->error('Compression failed: ' . $e->getMessage());
                return 1;
            }
            $uploadPath = $gzPath;
            $uploadName = str_ends_with($fileName, '.gz') ? $fileName : ($fileName . '.gz');
            $this->info('Compressed file: ' . $uploadPath);
        }

        $this->info('Uploading dump to Google Drive...');
        try {
            $this->driveService->uploadFile($uploadPath, $uploadName);
        } catch (Exception $e) {
            $this->error('Upload failed: ' . $e->getMessage());
            return 1;
        }

        $this->info('Backup uploaded successfully as "' . $uploadName . '"');

        if (is_file($dumpPath)) {
            @unlink($dumpPath);
        }
        if ($compress && is_file($gzPath)) {
            @unlink($gzPath);
        }

        return 0;
    }

    /**
     * Gzip-compress a file using streaming to avoid high memory usage.
     *
     * @throws Exception
     */
    private function gzipFile(string $source, string $destination): void
    {
        if (!is_file($source)) {
            throw new Exception('Source file not found for compression: ' . $source);
        }
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new Exception('Unable to open source file for reading: ' . $source);
        }
        $out = gzopen($destination, 'wb9');
        if ($out === false) {
            fclose($in);
            throw new Exception('Unable to open destination file for writing: ' . $destination);
        }
        while (!feof($in)) {
            $data = fread($in, 524288); // 512 KB chunks
            if ($data === false) {
                break;
            }
            if ($data !== '') {
                gzwrite($out, $data);
            }
        }
        fclose($in);
        gzclose($out);

        if (!is_file($destination) || filesize($destination) === 0) {
            throw new Exception('Compressed file not created or empty: ' . $destination);
        }
    }
}
