<?php

use Illuminate\Support\Facades\Route;
use Artryazanov\LaravelMysqlDriveBackup\Http\Controllers\DriveOAuthController;

Route::get('/google/drive/callback', [DriveOAuthController::class, 'callback'])
    ->name('drive.oauth.callback');
