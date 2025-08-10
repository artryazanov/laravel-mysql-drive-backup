<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Commands;

use Illuminate\Console\Command;
use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Exception;

class AuthorizeDriveCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'backup:authorize-drive';

    /**
     * The console command description.
     */
    protected $description = 'Perform Google Drive OAuth2 authorization and save the token';

    /** @var GoogleDriveService */
    private GoogleDriveService $driveService;

    public function __construct(GoogleDriveService $driveService)
    {
        parent::__construct();
        $this->driveService = $driveService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $authUrl = $this->driveService->getAuthUrl();
        $this->info('Open the following URL in your browser to authorize Google Drive:');
        $this->line($authUrl);
        $this->info('After approval, Google will redirect you to: ' . (string) config('drivebackup.redirect_uri'));

        if ($this->confirm('Do you want to paste the code here manually? (usually not needed if callback is configured)', false)) {
            $code = $this->ask('Paste the value of the "code" query parameter');
            try {
                $this->driveService->storeAuthToken((string)$code);
            } catch (Exception $e) {
                $this->error('Failed to obtain token: ' . $e->getMessage());
                return 1;
            }
            $this->info('OAuth2 token has been saved to: ' . config('drivebackup.token_file'));
        } else {
            $this->info('Complete the flow in the browser. The token will be saved by the callback route automatically.');
        }

        $this->info('You can now run the backup command: backup:mysql-to-drive');
        return 0;
    }
}
