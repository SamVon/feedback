<?php

namespace App\Console\Commands;

use App\Jobs\BuildFeedbackExport;
use App\Models\ExportJob;
use Illuminate\Console\Command;

class RecoverExports extends Command
{
    protected $signature = 'feedback:recover-exports';

    protected $description = 'Requeue stalled, unexpired exports after worker failure or a dispatch interruption.';

    public function handle(): int
    {
        $count = 0;
        foreach (ExportJob::whereIn('status', ['queued', 'processing'])->where('updated_at', '<', now()->subMinutes(10))->where('expires_at', '>', now())->cursor() as $job) {
            BuildFeedbackExport::dispatch($job->id);
            $job->touch();
            $count++;
        }
        $this->info("Requeued {$count} exports.");

        return self::SUCCESS;
    }
}
