<?php

namespace Artryazanov\LaravelMysqlDriveBackup\Commands;

use Artryazanov\LaravelMysqlDriveBackup\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Remove outdated backup files from Google Drive based on retention policy.
 */
class CleanupDriveBackupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'backup:cleanup-drive';

    /**
     * The console command description.
     */
    protected $description = 'Remove outdated backups from Google Drive according to retention rules';

    public function __construct(protected GoogleDriveService $drive)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pattern = (string) config('drivebackup.backup_file_name');
        if ($pattern === '') {
            $this->error('Config backup_file_name is empty.');

            return 1;
        }

        $regex = $this->buildRegex($pattern);
        $folderId = config('drivebackup.drive_backup_folder_id');

        try {
            $files = $this->drive->listBackupFiles($folderId);
        } catch (\Exception $e) {
            $this->error('Failed to list files: '.$e->getMessage());

            return 1;
        }

        $backups = [];
        foreach ($files as $file) {
            if (preg_match($regex, $file['name'])) {
                $backups[] = [
                    'id' => $file['id'],
                    'name' => $file['name'],
                    'time' => Carbon::parse($file['modifiedTime']),
                ];
            }
        }

        if (empty($backups)) {
            $this->info('No backups found.');

            return 0;
        }

        usort($backups, fn ($a, $b) => $a['time']->timestamp <=> $b['time']->timestamp);

        $toKeep = [];
        $toDelete = [];

        // Daily: keep last per day
        $byDay = [];
        foreach ($backups as $file) {
            $key = $file['time']->toDateString();
            $byDay[$key][] = $file;
        }
        foreach ($byDay as $filesByDay) {
            $last = array_pop($filesByDay);
            $toKeep[] = $last;
            foreach ($filesByDay as $old) {
                $toDelete[] = $old;
            }
        }

        $now = Carbon::now();

        // Weekly: keep last per week except current and previous week
        $currentWeek = $now->isoWeekYear.'-'.$now->isoWeek;
        $previousWeekCarbon = $now->copy()->subWeek();
        $previousWeek = $previousWeekCarbon->isoWeekYear.'-'.$previousWeekCarbon->isoWeek;

        $byWeek = [];
        foreach ($toKeep as $file) {
            $wk = $file['time']->isoWeekYear.'-'.$file['time']->isoWeek;
            $byWeek[$wk][] = $file;
        }
        $toKeep = [];
        foreach ($byWeek as $week => $filesByWeek) {
            if ($week === $currentWeek || $week === $previousWeek) {
                foreach ($filesByWeek as $f) {
                    $toKeep[] = $f;
                }
            } else {
                $last = array_pop($filesByWeek);
                $toKeep[] = $last;
                foreach ($filesByWeek as $old) {
                    $toDelete[] = $old;
                }
            }
        }

        // Monthly: keep last per month except current and previous month
        $currentMonth = $now->format('Y-m');
        $previousMonth = $now->copy()->subMonth()->format('Y-m');

        $byMonth = [];
        foreach ($toKeep as $file) {
            $m = $file['time']->format('Y-m');
            $byMonth[$m][] = $file;
        }
        $toKeep = [];
        foreach ($byMonth as $month => $filesByMonth) {
            if ($month === $currentMonth || $month === $previousMonth) {
                foreach ($filesByMonth as $f) {
                    $toKeep[] = $f;
                }
            } else {
                $last = array_pop($filesByMonth);
                $toKeep[] = $last;
                foreach ($filesByMonth as $old) {
                    $toDelete[] = $old;
                }
            }
        }

        // Yearly: keep last per year except current and previous year
        $currentYear = $now->format('Y');
        $previousYear = $now->copy()->subYear()->format('Y');

        $byYear = [];
        foreach ($toKeep as $file) {
            $y = $file['time']->format('Y');
            $byYear[$y][] = $file;
        }
        $toKeep = [];
        foreach ($byYear as $year => $filesByYear) {
            $year = (string) $year;
            if ($year === $currentYear || $year === $previousYear) {
                foreach ($filesByYear as $f) {
                    $toKeep[] = $f;
                }
            } else {
                $last = array_pop($filesByYear);
                $toKeep[] = $last;
                foreach ($filesByYear as $old) {
                    $toDelete[] = $old;
                }
            }
        }

        if (empty($toDelete)) {
            $this->info('No outdated backups to delete.');

            return 0;
        }

        foreach ($toDelete as $file) {
            try {
                $this->drive->deleteFile($file['id']);
                $this->line('Deleted backup: '.$file['name']);
            } catch (\Exception $e) {
                $this->error('Failed to delete '.$file['name'].': '.$e->getMessage());
            }
        }

        $this->info('Cleanup completed.');

        return 0;
    }

    /**
     * Build regex to match backup file name pattern from config.
     */
    protected function buildRegex(string $pattern): string
    {
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\\{timestamp\\}', '.*', $regex);
        if (! str_ends_with($pattern, '.gz')) {
            $regex .= '(?:\\.gz)?';
        }

        return '#^'.$regex.'$#';
    }
}
