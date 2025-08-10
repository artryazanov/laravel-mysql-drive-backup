<?php

namespace Artryazanov\LaravelMysqlDriveBackup;

use Artryazanov\LaravelMysqlDriveBackup\Commands\AuthorizeDriveCommand;
use Artryazanov\LaravelMysqlDriveBackup\Commands\BackupMysqlToDriveCommand;
use Artryazanov\LaravelMysqlDriveBackup\Services\DumpService;
use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Illuminate\Support\ServiceProvider;

class DriveBackupServiceProvider extends ServiceProvider
{
    /**
     * Register bindings and configuration.
     */
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(__DIR__.'/../config/drivebackup.php', 'drivebackup');

        // Bind services
        $this->app->singleton(GoogleDriveService::class, function ($app) {
            $config = $app['config']->get('drivebackup');

            return new GoogleDriveService(
                (string) ($config['client_id'] ?? ''),
                (string) ($config['client_secret'] ?? ''),
                (string) ($config['token_file'] ?? storage_path('app/google_drive_token.json'))
            );
        });

        $this->app->singleton(DumpService::class, function ($app) {
            return new DumpService;
        });
    }

    /**
     * Bootstrap publishing and console commands.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__.'/../config/drivebackup.php' => config_path('drivebackup.php'),
            ], 'config');

            // Register commands
            $this->commands([
                BackupMysqlToDriveCommand::class,
                AuthorizeDriveCommand::class,
            ]);
        }

        // Load package web routes (OAuth2 callback)
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
