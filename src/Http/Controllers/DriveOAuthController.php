<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;

class DriveOAuthController extends Controller
{
    /**
     * Google redirects back with ?code=... or ?error=...
     * Exchange the code for tokens and persist them.
     */
    public function callback(Request $request, GoogleDriveService $service)
    {
        $error = $request->query('error');
        if ($error) {
            return response('Google OAuth error: ' . $error, 400);
        }

        $code = $request->query('code');
        if (!$code) {
            return response('Missing authorization code.', 400);
        }

        try {
            $service->storeAuthToken((string) $code);
        } catch (\Throwable $e) {
            return response('Failed to exchange code: ' . $e->getMessage(), 500);
        }

        return response('Google Drive authorization completed successfully. Token saved.');
    }
}
