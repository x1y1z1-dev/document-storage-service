<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FileManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeleteExpiredFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:delete-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all files whose retention period has elapsed.';

    /**
     * Execute the console command.
     */
    public function handle(FileManager $fileManager): int
    {
        Log::debug('Starting expired file deletion run.');

        $summary = $fileManager->deleteExpired();

        Log::debug(
            "Expired file deletion complete. Found: {$summary['found']}, Deleted: {$summary['deleted']}, Failed: {$summary['failed']}."
        );

        return Command::SUCCESS;
    }
}
