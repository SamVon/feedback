<?php

namespace App\Jobs;

use App\Application\Access;
use App\Application\Audit;
use App\Application\ExportRenderer;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class BuildFeedbackExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $exportId) {}

    public function handle(): void
    {
        $job = ExportJob::findOrFail($this->exportId);
        if ($job->status === 'ready') {
            return;
        }
        $user = User::findOrFail($job->user_id);
        Access::check($user, 'report.export');
        Access::check($user, 'feedback.read');
        abort_unless($job->expires_at->isFuture(), 410);
        $job->update(['status' => 'processing', 'error' => null]);
        if ($job->summary !== null) {
            Access::check($user, 'analytics.read');
        }
        $path = 'exports/'.$job->id.'.'.$job->format;
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        Storage::disk('local')->makeDirectory('exports');
        try {
            app(ExportRenderer::class)->write($job, Storage::disk('local')->path($temporary));
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($temporary);
            throw $exception;
        }
        Storage::disk('local')->move($temporary, $path);
        $job->update(['status' => 'ready', 'file_ref' => $path]);
        Audit::record((string) $user->id, 'export.ready', 'export', $job->id);
    }

    public function failed(?\Throwable $exception): void
    {
        ExportJob::whereKey($this->exportId)->update(['status' => 'failed', 'error' => 'Export failed. Review worker logs and permissions.']);
    }
}
