<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Unit;

use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;
use Exception;

/**
 * @internal
 */
class AuthorizeDriveCommandTest extends TestCase
{
    public function test_manual_paste_stores_token(): void
    {
        $drive = new class extends GoogleDriveService {
            public ?string $saved = null;
            public function __construct() {}
            public function getAuthUrl(): string { return 'http://example.com/auth'; }
            public function storeAuthToken(string $authCode): void { $this->saved = $authCode; }
        };
        $this->app->instance(GoogleDriveService::class, $drive);

        $this->artisan('backup:authorize-drive')
            ->expectsConfirmation('Do you want to paste the code here manually? (usually not needed if callback is configured)', 'yes')
            ->expectsQuestion('Paste the value of the "code" query parameter', 'my-code')
            ->expectsOutputToContain('OAuth2 token has been saved to:')
            ->expectsOutputToContain('You can now run the backup command: backup:mysql-to-drive')
            ->assertExitCode(0);

        $this->assertSame('my-code', $drive->saved);
    }

    public function test_returns_error_when_storing_token_fails(): void
    {
        $drive = new class extends GoogleDriveService {
            public function __construct() {}
            public function getAuthUrl(): string { return 'http://auth'; }
            public function storeAuthToken(string $authCode): void { throw new Exception('boom'); }
        };
        $this->app->instance(GoogleDriveService::class, $drive);

        $this->artisan('backup:authorize-drive')
            ->expectsConfirmation('Do you want to paste the code here manually? (usually not needed if callback is configured)', 'yes')
            ->expectsQuestion('Paste the value of the "code" query parameter', 'code')
            ->expectsOutputToContain('Failed to obtain token: boom')
            ->assertExitCode(1);
    }

    public function test_prompts_to_complete_flow_in_browser_when_not_manual(): void
    {
        $drive = new class extends GoogleDriveService {
            public bool $called = false;
            public function __construct() {}
            public function getAuthUrl(): string { return 'http://auth'; }
            public function storeAuthToken(string $authCode): void { $this->called = true; }
        };
        $this->app->instance(GoogleDriveService::class, $drive);

        $this->artisan('backup:authorize-drive')
            ->expectsConfirmation('Do you want to paste the code here manually? (usually not needed if callback is configured)', 'no')
            ->expectsOutputToContain('Complete the flow in the browser. The token will be saved by the callback route automatically.')
            ->expectsOutputToContain('You can now run the backup command: backup:mysql-to-drive')
            ->assertExitCode(0);

        $this->assertFalse($drive->called);
    }
}
