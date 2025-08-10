<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Services;

use Exception;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveService
{
    protected Client $client;

    protected string $tokenFile;

    /**
     * Configure Google API Client for OAuth2 with given credentials.
     */
    public function __construct(string $clientId, string $clientSecret, string $tokenFile)
    {
        $this->tokenFile = $tokenFile;

        $this->client = new Client;
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri((string) config('drivebackup.redirect_uri'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->setScopes([Drive::DRIVE_FILE]);
    }

    /**
     * Generate OAuth2 authorization URL.
     */
    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    /**
     * Exchange auth code and store token (with refresh_token) to file.
     *
     * @throws Exception
     */
    public function storeAuthToken(string $authCode): void
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($authCode);
        if (isset($token['error'])) {
            throw new Exception('Error fetching Google OAuth token: '.$token['error']);
        }
        $this->client->setAccessToken($token);
        $this->saveTokenToFile($this->client->getAccessToken());
    }

    /**
     * Upload file to Google Drive under the provided name; refresh token when needed.
     *
     * @throws Exception
     */
    public function uploadFile(string $filePath, string $driveFileName): void
    {
        if (! is_file($this->tokenFile)) {
            throw new Exception('Token file not found, authorization required.');
        }
        $saved = json_decode((string) file_get_contents($this->tokenFile), true) ?: [];
        $this->client->setAccessToken($saved);

        if ($this->client->isAccessTokenExpired()) {
            if ($this->client->getRefreshToken()) {
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                if (isset($newToken['error'])) {
                    throw new Exception('Failed to refresh access token: '.$newToken['error']);
                }
                $this->saveTokenToFile($this->client->getAccessToken());
            } else {
                throw new Exception('No refresh token available. Re-authorize.');
            }
        }

        $drive = new Drive($this->client);
        $fileMeta = new DriveFile(['name' => $driveFileName]);
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception('Failed to read local file for upload.');
        }

        $drive->files->create($fileMeta, [
            'data' => $content,
            'mimeType' => 'application/octet-stream',
            'uploadType' => 'multipart',
        ]);
    }

    /**
     * Save token JSON to file.
     *
     * @throws Exception
     */
    private function saveTokenToFile(array $tokenData): void
    {
        $dir = dirname($this->tokenFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (file_put_contents($this->tokenFile, json_encode($tokenData)) === false) {
            throw new Exception('Unable to persist OAuth token to: '.$this->tokenFile);
        }
    }
}
