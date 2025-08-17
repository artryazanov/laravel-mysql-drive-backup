<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Services;

use Exception;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

/**
 * Service wrapper around Google Drive API client used for backup and restore.
 */
class GoogleDriveService
{
    protected Client $client;

    protected string $tokenFile;

    public function __construct(string $clientId, string $clientSecret, string $tokenFile, ?string $redirectUri = null)
    {
        $this->tokenFile = $tokenFile;

        $this->client = new Client;
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        if ($redirectUri) {
            $this->client->setRedirectUri($redirectUri);
        }
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
            throw new Exception('Google token error: '.$token['error']);
        }
        $this->client->setAccessToken($token);
        $this->saveTokenToFile($this->client->getAccessToken());
    }

    /**
     * Ensure we have a valid access token, refreshing if needed.
     *
     * @throws Exception
     */
    protected function ensureAccessToken(): void
    {
        if (! file_exists($this->tokenFile)) {
            throw new Exception('Token file not found. Run authorization first.');
        }

        $token = json_decode((string) file_get_contents($this->tokenFile), true) ?: [];
        $this->client->setAccessToken($token);

        if ($this->client->isAccessTokenExpired()) {
            $refresh = $this->client->getRefreshToken();
            if (! $refresh) {
                throw new Exception('No refresh token. Re-run authorization.');
            }
            $new = $this->client->fetchAccessTokenWithRefreshToken($refresh);
            if (isset($new['error'])) {
                throw new Exception('Failed to refresh access token: '.$new['error']);
            }
            $this->saveTokenToFile($this->client->getAccessToken());
        }
    }

    /**
     * List files (id, name, modifiedTime, mimeType) optionally within a folder.
     *
     * @return array<array{id:string, name:string, modifiedTime:string, mimeType:string}>
     *
     * @throws Exception
     */
    public function listBackupFiles(?string $folderId = null): array
    {
        $this->ensureAccessToken();
        $service = new Drive($this->client);

        $query = 'trashed = false';
        if ($folderId) {
            $query .= " and '{$folderId}' in parents";
        }

        $files = [];
        $pageToken = null;
        do {
            $params = [
                'q' => $query,
                'fields' => 'nextPageToken, files(id, name, mimeType, modifiedTime)',
                'orderBy' => 'modifiedTime desc',
                'pageSize' => 1000,
            ];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $resp = $service->files->listFiles($params);
            foreach ($resp->getFiles() as $f) {
                $files[] = [
                    'id' => $f->getId(),
                    'name' => $f->getName(),
                    'mimeType' => $f->getMimeType(),
                    'modifiedTime' => $f->getModifiedTime(),
                ];
            }
            $pageToken = $resp->getNextPageToken();
        } while ($pageToken);

        return $files;
    }

    /**
     * Download file contents from Drive to local path.
     *
     * @throws Exception
     */
    public function downloadFileTo(string $fileId, string $destPath): void
    {
        $this->ensureAccessToken();
        $service = new Drive($this->client);

        $response = $service->files->get($fileId, ['alt' => 'media']);
        $body = $response->getBody();

        $dir = dirname($destPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($destPath, 'w');
        if (! $fh) {
            throw new Exception('Unable to open file for writing: '.$destPath);
        }
        while (! $body->eof()) {
            fwrite($fh, $body->read(1024 * 1024));
        }
        fclose($fh);
    }

    /**
     * Upload file to Google Drive under the provided name.
     *
     * @throws Exception
     */
    public function uploadFile(string $filePath, string $driveFileName): void
    {
        $this->ensureAccessToken();
        $drive = new Drive($this->client);

        // Prepare file metadata
        $fileMeta = new DriveFile(['name' => $driveFileName]);

        // Enable deferred execution to obtain a request for resumable upload
        $this->client->setDefer(true);

        try {
            // Initiate a creation request without providing data
            $request = $drive->files->create($fileMeta);

            // Configure chunk size (1 MB) and initialize MediaFileUpload for resumable upload
            $chunkSizeBytes = 1 * 1024 * 1024; // 1 MB
            $media = new MediaFileUpload(
                $this->client,
                $request,
                'application/octet-stream',
                null,
                true,
                $chunkSizeBytes
            );

            $fileSize = @filesize($filePath);
            if ($fileSize !== false) {
                $media->setFileSize($fileSize);
            }

            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                throw new Exception('Unable to open file for reading: '.$filePath);
            }

            $status = false;
            while ($status === false && ! feof($handle)) {
                $chunk = fread($handle, $chunkSizeBytes);
                if ($chunk === false) {
                    fclose($handle);
                    throw new Exception('Error reading file: '.$filePath);
                }
                $status = $media->nextChunk($chunk);
            }

            fclose($handle);
        } finally {
            // Always disable deferred mode
            $this->client->setDefer(false);
        }
    }

    /**
     * Delete file from Google Drive by its ID.
     *
     * @throws Exception
     */
    public function deleteFile(string $fileId): void
    {
        $this->ensureAccessToken();
        $drive = new Drive($this->client);
        $drive->files->delete($fileId);
    }

    /**
     * Persist OAuth token to file.
     */
    private function saveTokenToFile(array $token): void
    {
        $dir = dirname($this->tokenFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($this->tokenFile, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($this->tokenFile, 0600);
    }
}
