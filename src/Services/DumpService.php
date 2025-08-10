<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Services;

/**
 * Service responsible for creating a MySQL dump file using mysqldump.
 *
 * It reads connection parameters from Laravel's database configuration
 * (the default connection) and writes the dump to a specified local path.
 */
class DumpService
{
    /**
     * Backward-compatible method: create the dump using the new createBackup method.
     */
    public function dump(string $path): void
    {
        $this->createBackup($path);
    }

    /**
     * Create a dump of the current MySQL database and save it to the given file path.
     *
     * @param string $path Absolute path to the .sql file to write.
     * @throws \RuntimeException If mysqldump fails.
     */
    public function createBackup(string $path): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException("Failed to create directory: {$directory}");
            }
        }

        // Read DB config from Laravel
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        if ($driver !== 'mysql') {
            throw new \RuntimeException("The default database connection is not MySQL (driver: {$driver}).");
        }

        $host = (string) (config("database.connections.{$connection}.host") ?? '127.0.0.1');
        $port = (string) (config("database.connections.{$connection}.port") ?? '3306');
        $username = (string) (config("database.connections.{$connection}.username") ?? 'root');
        $password = (string) (config("database.connections.{$connection}.password") ?? '');
        $database = (string) (config("database.connections.{$connection}.database") ?? '');

        if ($database === '') {
            throw new \RuntimeException('Database name is empty. Please configure database.connections.{default}.database');
        }

        $hostArg = $host !== '' ? '--host=' . escapeshellarg($host) : '';
        $portArg = $port !== '' ? '--port=' . escapeshellarg($port) : '';
        $userArg = $username !== '' ? '--user=' . escapeshellarg($username) : '';
        $passArg = ($password !== '') ? '--password=' . escapeshellarg($password) : '--password='; // avoid interactive prompt
        $dbArg = escapeshellarg($database);
        $outFileArg = escapeshellarg($path);

        // Build --ignore-table arguments from config
        $ignoreArgs = [];
        $excludeTables = config('drivebackup.exclude_tables');
        if (is_array($excludeTables) && !empty($excludeTables)) {
            foreach ($excludeTables as $table) {
                $table = trim((string) $table);
                if ($table !== '') {
                    $ignoreArgs[] = '--ignore-table=' . escapeshellarg($database . '.' . $table);
                }
            }
        }
        $ignoreStr = implode(' ', $ignoreArgs);

        // Use --result-file to avoid shell redirection differences between platforms
        $command = trim("mysqldump {$hostArg} {$portArg} {$userArg} {$passArg} {$dbArg} {$ignoreStr} --result-file={$outFileArg}");

        $output = [];
        $resultCode = 0;
        $this->runCommand($command, $output, $resultCode);

        if ($resultCode !== 0) {
            $message = "mysqldump failed with code {$resultCode}";
            if (!empty($output)) {
                $message .= ': ' . implode(' ', $output);
            }
            throw new \RuntimeException($message);
        }

        if (!is_file($path)) {
            throw new \RuntimeException('Dump file was not created: ' . $path);
        }
    }

    /**
     * Wrapper around exec() to allow tests to override execution.
     *
     * @param string $command
     * @param array<int,string> $output
     * @param int $resultCode
     */
    protected function runCommand(string $command, array &$output, int &$resultCode): void
    {
        exec($command, $output, $resultCode);
    }
}
