<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Commands;

use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Exception;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * Restore the MySQL database from a backup stored on Google Drive.
 *
 * Supports .sql, .gz and .zip files, optional table filtering via --only and
 * --except options with simple wildcard support (*).
 */
class RestoreMysqlFromDriveCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'backup:restore-mysql
        {mask : File name or mask (e.g. backup-*.sql|*.gz|*.zip)}
        {--only= : Comma-separated list of tables to restore (supports * wildcard)}
        {--except= : Comma-separated list of tables to exclude (supports * wildcard)}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Restore MySQL database from Google Drive backup with optional table filtering';

    public function __construct(protected GoogleDriveService $drive)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $mask = (string) $this->argument('mask');
        $only = $this->parseListOption($this->option('only'));
        $except = $this->parseListOption($this->option('except'));

        $restoreDir = rtrim((string) config('drivebackup.restore_temp_dir'), DIRECTORY_SEPARATOR);
        if (! is_dir($restoreDir)) {
            mkdir($restoreDir, 0775, true);
        }

        // 1) Find latest file by mask on Drive
        $this->info("Searching Google Drive for mask: {$mask}");
        $file = $this->findLatestDriveFileByMask($mask);
        if (! $file) {
            $this->error("File matching '{$mask}' not found on Google Drive.");

            return 1;
        }
        $this->line("Found: {$file['name']} (modified: {$file['modifiedTime']})");

        // 2) Download to temp directory
        $localPath = $restoreDir.DIRECTORY_SEPARATOR.$file['name'];
        $this->info('Downloading file from Google Drive...');
        $this->drive->downloadFileTo($file['id'], $localPath);
        $this->line('Saved to: '.$localPath);

        // 3) Prepare SQL files
        try {
            $sqlFiles = $this->prepareSqlFiles($localPath, $restoreDir);
        } catch (Exception $e) {
            $this->error('Extraction error: '.$e->getMessage());

            return 1;
        }

        // 4) Filter tables if required
        try {
            $sqlFiles = $this->filterSqlByTables($sqlFiles, $only, $except, $restoreDir);
        } catch (Exception $e) {
            $this->error('Table filtering error: '.$e->getMessage());

            return 1;
        }

        if (empty($sqlFiles)) {
            $this->warn('No SQL files left after filtering.');

            return 0;
        }

        // 5) Import into MySQL
        try {
            $this->importSqlFiles($sqlFiles);
        } catch (Exception $e) {
            $this->error('MySQL restore error: '.$e->getMessage());

            return 1;
        }

        $this->info('Restore completed successfully.');

        $this->cleanupTemp($restoreDir, $file['name']);

        return 0;
    }

    /**
     * Parse comma-separated option values into array.
     *
     * @return array<int, string>
     */
    protected function parseListOption(?string $opt): array
    {
        if (! $opt) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $opt)), fn ($s) => $s !== ''));
    }

    /**
     * Find latest Google Drive file matching mask.
     */
    protected function findLatestDriveFileByMask(string $mask): ?array
    {
        $folderId = config('drivebackup.drive_backup_folder_id');
        $files = $this->drive->listBackupFiles($folderId);

        $regex = $this->maskToRegex($mask);
        foreach ($files as $f) {
            if (preg_match($regex, $f['name'])) {
                return $f; // already sorted by modifiedTime desc
            }
        }

        return null;
    }

    /**
     * Prepare SQL files to import from downloaded archive.
     *
     * @return array<int, string>
     */
    protected function prepareSqlFiles(string $localPath, string $restoreDir): array
    {
        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        $sqlFiles = [];

        if ($ext === 'sql') {
            $sqlFiles[] = $localPath;
        } elseif ($ext === 'gz') {
            $target = $restoreDir.DIRECTORY_SEPARATOR.basename($localPath, '.gz');
            $this->gunzipTo($localPath, $target);
            $sqlFiles[] = $target;
        } elseif ($ext === 'zip') {
            $sqlFiles = $this->unzipSqlFiles($localPath, $restoreDir);
        } else {
            throw new Exception("Unsupported file type: .{$ext}");
        }

        return $sqlFiles;
    }

    /**
     * Decompress .gz archive into destination .sql file.
     */
    protected function gunzipTo(string $gzPath, string $destSql): void
    {
        $gz = gzopen($gzPath, 'rb');
        if (! $gz) {
            throw new Exception("Unable to open archive: {$gzPath}");
        }
        $out = fopen($destSql, 'wb');
        if (! $out) {
            gzclose($gz);
            throw new Exception("Unable to create file: {$destSql}");
        }
        while (! gzeof($gz)) {
            fwrite($out, gzread($gz, 1024 * 1024));
        }
        gzclose($gz);
        fclose($out);
    }

    /**
     * Extract all .sql files from a ZIP archive.
     *
     * @return array<int, string>
     */
    protected function unzipSqlFiles(string $zipPath, string $restoreDir): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new Exception("Unable to open ZIP: {$zipPath}");
        }

        $sqlFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'sql') {
                $target = $restoreDir.DIRECTORY_SEPARATOR.basename($entry);

                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    $zip->close();
                    throw new Exception("Unable to read ZIP entry: {$entry}");
                }
                $out = fopen($target, 'wb');
                if (! $out) {
                    fclose($stream);
                    $zip->close();
                    throw new Exception("Unable to create file: {$target}");
                }
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        break;
                    }
                    if ($chunk !== '') {
                        fwrite($out, $chunk);
                    }
                }
                fclose($stream);
                fclose($out);

                $sqlFiles[] = $target;
            }
        }
        $zip->close();

        if (empty($sqlFiles)) {
            throw new Exception('No .sql files found inside ZIP.');
        }

        return $sqlFiles;
    }

    /**
     * Filter SQL files by tables using --only/--except options.
     *
     * @return array<int, string>
     */
    protected function filterSqlByTables(array $sqlFiles, array $only, array $except, string $restoreDir): array
    {
        if (empty($only) && empty($except)) {
            return $sqlFiles;
        }

        if (count($sqlFiles) > 1) {
            return $this->filterMultiSqlFiles($sqlFiles, $only, $except);
        }

        $src = $sqlFiles[0];
        $dst = $restoreDir.DIRECTORY_SEPARATOR.'filtered-'.basename($src);

        $this->filterSingleSqlFile($src, $dst, $only, $except);

        if (filesize($dst) === 0) {
            @unlink($dst);

            return [];
        }

        return [$dst];
    }

    /**
     * Filter multiple SQL files where each file corresponds to a table.
     *
     * @return array<int, string>
     */
    protected function filterMultiSqlFiles(array $files, array $only, array $except): array
    {
        $map = [];
        foreach ($files as $f) {
            $tbl = strtolower(pathinfo($f, PATHINFO_FILENAME));
            $map[$tbl] = $f;
        }

        $select = array_keys($map);

        if (! empty($only)) {
            $select = $this->namesByMasks($select, $only);
        }
        if (! empty($except)) {
            $exclude = $this->namesByMasks($select, $except);
            $select = array_values(array_diff($select, $exclude));
        }

        $out = [];
        foreach ($select as $tbl) {
            $out[] = $map[$tbl];
        }

        return $out;
    }

    /**
     * Filter single big SQL file (mysqldump format) by table blocks.
     */
    protected function filterSingleSqlFile(string $src, string $dst, array $only, array $except): void
    {
        $in = fopen($src, 'rb');
        $out = fopen($dst, 'wb');
        if (! $in || ! $out) {
            if ($in) {
                fclose($in);
            }
            if ($out) {
                fclose($out);
            }
            throw new Exception('Unable to open files for filtering.');
        }

        $current = null;
        $allowed = true;

        $isAllowed = function (string $table) use ($only, $except): bool {
            $table = strtolower($table);
            if (! empty($only) && ! $this->matchesAnyMask($table, $only)) {
                return false;
            }
            if (! empty($except) && $this->matchesAnyMask($table, $except)) {
                return false;
            }

            return true;
        };

        while (($line = fgets($in)) !== false) {
            // Detect the start of a (possibly new) table block
            if (preg_match('/^-- Table structure for table `([^`]+)`/i', $line, $m)
                || preg_match('/^DROP TABLE IF EXISTS `([^`]+)`/i', $line, $m)
                || preg_match('/^CREATE TABLE `([^`]+)`/i', $line, $m)
                || preg_match('/^LOCK TABLES `([^`]+)`/i', $line, $m)
                || preg_match('/^INSERT INTO `([^`]+)`/i', $line, $m)) {

                $table = $m[1];

                // Switch current table context; previous table implicitly ends
                $current = $table;
                $allowed = $isAllowed($table);
            }

            if ($current === null) {
                // Outside of table-specific blocks: always keep
                fwrite($out, $line);
            } else {
                // Inside a table block: write or skip line immediately to avoid buffering
                if ($allowed) {
                    fwrite($out, $line);
                }

                // End of table block (for dumps with LOCK/UNLOCK)
                if (preg_match('/^UNLOCK TABLES;$/i', trim($line))) {
                    $current = null;
                    $allowed = true;
                }
            }
        }

        fclose($in);
        fclose($out);
    }

    /**
     * Import SQL files using mysql CLI.
     *
     * @throws Exception
     */
    protected function importSqlFiles(array $sqlFiles): void
    {
        $conn = config('database.default');
        $db = config("database.connections.{$conn}");
        if (! $db || ($db['driver'] ?? null) !== 'mysql') {
            throw new Exception('Only MySQL driver is supported.');
        }

        $hostArg = $db['host'] ? '--host='.escapeshellarg($db['host']) : '';
        $portArg = $db['port'] ? '--port='.escapeshellarg((string) $db['port']) : '';
        $userArg = $db['username'] ? '--user='.escapeshellarg($db['username']) : '';
        $passArg = array_key_exists('password', $db)
            ? '--password='.escapeshellarg((string) $db['password'])
            : '--password=';

        foreach ($sqlFiles as $path) {
            $this->info('Importing: '.basename($path));
            $cmd = "mysql {$hostArg} {$portArg} {$userArg} {$passArg} ".escapeshellarg((string) $db['database']).
                ' < '.escapeshellarg($path).' 2>&1';
            $code = 0;
            passthru($cmd, $code);
            if ($code !== 0) {
                throw new Exception("mysql exited with code {$code}.");
            }
        }
    }

    /**
     * Remove temporary artifacts created during restore.
     */
    protected function cleanupTemp(string $restoreDir, string $downloadedFileName): void
    {
        // Intentionally left blank; implement custom cleanup if needed.
        // Users may want to keep files for inspection.
    }

    /**
     * Convert simple wildcard mask to regex.
     */
    protected function maskToRegex(string $mask): string
    {
        $escaped = preg_quote($mask, '#');
        $escaped = str_replace('\\*', '.*', $escaped);

        return '#^'.$escaped.'$#i';
    }

    protected function matchesAnyMask(string $name, array $masks): bool
    {
        $name = strtolower($name);
        foreach ($masks as $m) {
            if (preg_match($this->maskToRegex($m), $name)) {
                return true;
            }
        }

        return false;
    }

    protected function namesByMasks(array $candidates, array $masks): array
    {
        $out = [];
        foreach ($candidates as $n) {
            if ($this->matchesAnyMask($n, $masks)) {
                $out[] = $n;
            }
        }

        return $out;
    }
}
