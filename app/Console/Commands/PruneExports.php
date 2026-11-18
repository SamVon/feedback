<?php

namespace App\Console\Commands;

use App\Models\ExportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneExports extends Command
{
    protected $signature = 'feedback:prune-exports';

    protected $description = 'Remove expired private export files while retaining their audit/job records.';

    public function handle(): int
    {
        foreach (ExportJob::whereNotNull('file_ref')->where('expires_at', '<=', now())->cursor() as $job) {
            Storage::disk('local')->delete($job->file_ref);
            $job->update(['file_ref' => null, 'status' => 'expired']);
        }

        return self::SUCCESS;
    }
}
