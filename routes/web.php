<?php

use Artryazanov\LaravelMysqlDriveBackup\Http\Controllers\DriveOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/google/drive/callback', [DriveOAuthController::class, 'callback'])
    ->name('drive.oauth.callback');
