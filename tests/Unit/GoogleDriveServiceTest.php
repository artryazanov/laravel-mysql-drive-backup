<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Tests\Unit;

use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Artryazanov\LaravelMysqlDriveBackup\Tests\TestCase;
use Exception;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use ReflectionClass;

class GoogleDriveServiceTest extends TestCase
{
    private string $tokenFile;
    private GoogleDriveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tokenFile = sys_get_temp_dir() . '/test-token-' . uniqid() . '.json';
        Config::set('drivebackup.token_file', $this->tokenFile);
        Config::set('drivebackup.drive_backup_folder_id', 'test-folder-id');

        $this->service = new GoogleDriveService('client-id', 'client-secret', $this->tokenFile, 'http://localhost/callback');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tokenFile)) {
            unlink($this->tokenFile);
        }
        parent::tearDown();
    }

    private function mockGoogleClient(array $responses): void
    {
        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new GuzzleClient(['handler' => $handlerStack]);

        $reflection = new ReflectionClass($this->service);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        /** @var Client $client */
        $client = $clientProp->getValue($this->service);
        $client->setHttpClient($httpClient);
    }
    
    private function saveDummyToken(array $tokenData = null): void
    {
        $data = $tokenData ?? ['access_token' => 'dummy', 'refresh_token' => 'dummy-refresh', 'created' => time(), 'expires_in' => 3600];
        file_put_contents($this->tokenFile, json_encode($data));
    }

    public function test_upload_throws_when_token_missing(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/token file not found/i');

        $this->service->uploadFile(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dummy.sql', 'dummy.sql');
    }

    public function test_get_auth_url_returns_valid_url(): void
    {
        $url = $this->service->getAuthUrl();
        $this->assertStringContainsString('https://accounts.google.com/o/oauth2', $url);
        $this->assertStringContainsString('client_id=client-id', $url);
        $this->assertStringContainsString('redirect_uri=' . urlencode('http://localhost/callback'), $url);
    }

    public function test_store_auth_token_success(): void
    {
        $this->mockGoogleClient([
            new Response(200, [], json_encode(['access_token' => 'test-token', 'refresh_token' => 'test-refresh', 'expires_in' => 3600, 'created' => time()]))
        ]);

        $this->service->storeAuthToken('auth-code');

        $this->assertFileExists($this->tokenFile);
        $tokenData = json_decode(file_get_contents($this->tokenFile), true);
        $this->assertEquals('test-token', $tokenData['access_token']);
        $this->assertEquals('test-refresh', $tokenData['refresh_token']);
    }

    public function test_store_auth_token_throws_on_error(): void
    {
        $this->mockGoogleClient([
            new Response(200, [], json_encode(['error' => 'invalid_grant']))
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Google token error: invalid_grant');

        $this->service->storeAuthToken('invalid-code');
    }

    public function test_ensure_access_token_refreshes_when_expired(): void
    {
        // Expired token
        $this->saveDummyToken(['access_token' => 'expired_token', 'refresh_token' => 'refresh_me', 'created' => time() - 7200, 'expires_in' => 3600]);

        $this->mockGoogleClient([
            new Response(200, [], json_encode(['access_token' => 'new-token', 'expires_in' => 3600, 'created' => time()]))
        ]);

        // Using reflection to call protected method
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('ensureAccessToken');
        $method->setAccessible(true);
        $method->invoke($this->service);

        $tokenData = json_decode(file_get_contents($this->tokenFile), true);
        $this->assertEquals('new-token', $tokenData['access_token']);
    }
    
    public function test_ensure_access_token_throws_when_refresh_fails(): void
    {
        $this->saveDummyToken(['access_token' => 'expired_token', 'refresh_token' => 'refresh_me', 'created' => time() - 7200, 'expires_in' => 3600]);

        $this->mockGoogleClient([
            new Response(200, [], json_encode(['error' => 'invalid_client']))
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to refresh access token: invalid_client');

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('ensureAccessToken');
        $method->setAccessible(true);
        $method->invoke($this->service);
    }
    
    public function test_ensure_access_token_throws_when_no_refresh_token(): void
    {
        $this->saveDummyToken(['access_token' => 'expired_token', 'created' => time() - 7200, 'expires_in' => 3600]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No refresh token. Re-run authorization.');

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('ensureAccessToken');
        $method->setAccessible(true);
        $method->invoke($this->service);
    }

    public function test_list_backup_files(): void
    {
        $this->saveDummyToken();
        
        $this->mockGoogleClient([
            new Response(200, [], json_encode([
                'files' => [
                    ['id' => '123', 'name' => 'backup.sql', 'mimeType' => 'text/plain', 'modifiedTime' => '2023-01-01T00:00:00.000Z']
                ],
                'nextPageToken' => null
            ]))
        ]);

        $files = $this->service->listBackupFiles('folder-id');

        $this->assertCount(1, $files);
        $this->assertEquals('123', $files[0]['id']);
        $this->assertEquals('backup.sql', $files[0]['name']);
    }

    public function test_list_backup_files_with_pagination(): void
    {
        $this->saveDummyToken();
        
        $this->mockGoogleClient([
            new Response(200, [], json_encode([
                'files' => [
                    ['id' => '1', 'name' => 'b1.sql', 'mimeType' => 'text/plain', 'modifiedTime' => '2023-01-01T00:00:00.000Z']
                ],
                'nextPageToken' => 'token-page-2'
            ])),
            new Response(200, [], json_encode([
                'files' => [
                    ['id' => '2', 'name' => 'b2.sql', 'mimeType' => 'text/plain', 'modifiedTime' => '2023-01-02T00:00:00.000Z']
                ],
                'nextPageToken' => null
            ]))
        ]);

        $files = $this->service->listBackupFiles();

        $this->assertCount(2, $files);
        $this->assertEquals('1', $files[0]['id']);
        $this->assertEquals('2', $files[1]['id']);
    }

    public function test_download_file_to(): void
    {
        $this->saveDummyToken();
        
        $this->mockGoogleClient([
            // Request to Google Drive get with alt=media
            new Response(200, [], 'file contents')
        ]);

        $dest = sys_get_temp_dir() . '/downloaded-file.sql';
        if (file_exists($dest)) {
            unlink($dest);
        }

        $this->service->downloadFileTo('fileId', $dest);

        $this->assertFileExists($dest);
        $this->assertEquals('file contents', file_get_contents($dest));
        unlink($dest);
    }

    public function test_delete_file(): void
    {
        $this->saveDummyToken();

        $this->mockGoogleClient([
            new Response(204, []) // No Content for delete
        ]);

        $this->service->deleteFile('fileId');
        
        // Assert we got here without exceptions
        $this->assertTrue(true);
    }

    public function test_upload_file_success(): void
    {
        $this->saveDummyToken();

        $filePath = sys_get_temp_dir() . '/test-upload-' . uniqid() . '.sql';
        file_put_contents($filePath, 'dump data');

        $reflection = new ReflectionClass($this->service);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $originalClient = $clientProp->getValue($this->service);

        // We use a partial mock here to override just the `execute` method, bypassing a 
        // bug/quirk in google/apiclient's HTTP Rest deserializer during resumable uploads
        // where it attempts to deserialize a 200 OK initial response into a DriveFile.
        $mockClient = \Mockery::mock($originalClient)->makePartial();
        
        $mockClient->shouldReceive('execute')
            ->once()
            ->andReturn(new Response(200, ['Location' => 'https://upload.google.com/upload/drive/v3/files?uploadType=resumable&upload_id=XYZ']));

        $mockClient->shouldReceive('execute')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['id' => 'uploaded-file-id', 'name' => 'backup.sql'])));

        $clientProp->setValue($this->service, $mockClient);

        $this->service->uploadFile($filePath, 'backup.sql');

        // Assert no exceptions thrown during upload
        $this->assertTrue(true);
        unlink($filePath);
    }
}
