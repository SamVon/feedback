<?php

namespace App\Application;

use App\Jobs\BuildFeedbackExport;
use App\Models\ExportJob;
use App\Models\FeedbackResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class Exports
{
    public function create(User $user, array $filters, string $format = 'csv', ?Builder $membership = null): ExportJob
    {
        Access::check($user, 'report.export');
        Access::check($user, 'feedback.read');
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 422, 'Unsupported export format.');
        $filters = ReportFilters::validate($filters);

        return DB::transaction(function () use ($user, $filters, $format, $membership) {
            $job = ExportJob::create(['user_id' => $user->id, 'filters' => $filters, 'format' => $format, 'locale' => app()->getLocale(), 'snapshot_at' => now(), 'expires_at' => now()->addDay(), 'status' => 'queued']);
            // Freeze membership in one statement, so delayed/retried workers never add newer responses.
            DB::table('export_job_responses')->insertUsing(
                ['export_job_id', 'feedback_response_id'],
                ($membership ? clone $membership : ReportFilters::query($filters))->reorder()->select([])
                    ->selectRaw('? as export_job_id, feedback_responses.id as feedback_response_id', [$job->id]),
            );
            if ($format !== 'csv' && $user->can('analytics.read')) {
                $members = FeedbackResponse::whereIn('id', DB::table('export_job_responses')->where('export_job_id', $job->id)->select('feedback_response_id'))->select('id');
                $job->update(['summary' => app(Analytics::class)->summary($user, $filters, $members)]);
            }
            Audit::record((string) $user->id, 'export.request', 'export', $job->id, ['filters' => $filters]);
            BuildFeedbackExport::dispatch($job->id)->afterCommit();

            return $job;
        });
    }

    public function download(User $user, ExportJob $job)
    {
        Access::check($user, 'report.export');
        Access::check($user, 'feedback.read');
        if ($job->summary !== null) {
            Access::check($user, 'analytics.read');
        }
        abort_unless($job->user_id === $user->id, 403);
        abort_unless($job->status === 'ready' && $job->expires_at->isFuture(), 410, 'Export unavailable or expired.');
        abort_unless(Storage::disk('local')->exists($job->file_ref), 404);
        Audit::record((string) $user->id, 'export.download', 'export', $job->id);

        $mime = match ($job->format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            default => 'text/csv; charset=UTF-8',
        };

        return Storage::disk('local')->download($job->file_ref, 'easy-gold-feedback.'.$job->format, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store']);
    }

    public static function safeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        // Prefix before all content, including whitespace/control prefixes recognized by spreadsheets.
        return preg_match('/^[\s\x00-\x1F]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value;
    }
}
