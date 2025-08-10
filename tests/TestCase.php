<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests;

use Artryazanov\LaravelMysqlDriveBackup\DriveBackupServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DriveBackupServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Set a MySQL-like default connection for tests that require it
        $app['config']->set('database.default', 'mysqltest');
        $app['config']->set('database.connections.mysqltest', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'testdb',
            'username' => 'testuser',
            'password' => 'testpass',
        ]);
    }
}
