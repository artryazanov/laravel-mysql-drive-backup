<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Feature;

use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;
use Exception;

class DriveOAuthControllerTest extends TestCase
{
    public function test_callback_handles_google_oauth_error(): void
    {
        // Act
        $response = $this->get(route('drive.oauth.callback', ['error' => 'access_denied']));

        // Assert
        $response->assertStatus(400);
        $response->assertSee('Google OAuth error: access_denied');
    }

    public function test_callback_handles_missing_code(): void
    {
        // Act
        $response = $this->get(route('drive.oauth.callback'));

        // Assert
        $response->assertStatus(400);
        $response->assertSee('Missing authorization code.');
    }

    public function test_callback_handles_exchange_token_exception(): void
    {
        // Arrange
        $mockService = $this->mock(GoogleDriveService::class);
        $mockService->shouldReceive('storeAuthToken')
            ->once()
            ->with('valid_code')
            ->andThrow(new Exception('Invalid grant'));

        // Act
        $response = $this->get(route('drive.oauth.callback', ['code' => 'valid_code']));

        // Assert
        $response->assertStatus(500);
        $response->assertSee('Failed to exchange code: Invalid grant');
    }

    public function test_callback_stores_token_successfully(): void
    {
        // Arrange
        $mockService = $this->mock(GoogleDriveService::class);
        $mockService->shouldReceive('storeAuthToken')
            ->once()
            ->with('valid_code');

        // Act
        $response = $this->get(route('drive.oauth.callback', ['code' => 'valid_code']));

        // Assert
        $response->assertStatus(200);
        $response->assertSee('Google Drive authorization completed successfully. Token saved.');
    }
}
