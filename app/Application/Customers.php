<?php

namespace App\Application;

use App\Models\CohortMember;
use App\Models\CohortSnapshot;
use App\Models\FeedbackInvitation;
use App\Models\FeedbackResponse;
use App\Models\PromptEvent;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class Customers
{
    public function listing(User $user, array $filters, string $source = 'complete'): Builder
    {
        Access::check($user, 'customers.read');
        $filters = ReportFilters::validate($filters);
        $state = $this->snapshot($user, $source);
        $snapshot = $state['selected'];
        $responses = ReportFilters::query($filters)->select('actor_user_id')
            ->selectRaw('COUNT(*) as response_count, MAX(received_at) as latest_at')->groupBy('actor_user_id');
        $invitations = FeedbackInvitation::query()->select('feedback_invitations.actor_user_id')
            ->leftJoin('prompt_events', 'prompt_events.invitation_id', '=', 'feedback_invitations.id')
            ->when($filters['survey_version_id'] ?? null, fn (Builder $q, string $id) => $q->where('survey_version_id', $id))
            ->when(! isset($filters['survey_version_id']) && isset($filters['survey_id']), fn (Builder $q) => $q->whereIn('survey_version_id', SurveyVersion::where('survey_id', $filters['survey_id'])->select('id')))
            ->selectRaw('COUNT(DISTINCT feedback_invitations.id) as invitation_count')
            ->selectRaw("MAX(CASE WHEN prompt_events.event_type = 'shown' THEN 1 ELSE 0 END) as shown_count")
            ->groupBy('feedback_invitations.actor_user_id');
        $columns = ['cohort_members.id', 'cohort_members.actor_user_id', 'cohort_members.identity_complete', 'cohort_members.cohort_snapshot_id'];
        $query = $this->roster($user, $snapshot)
            ->leftJoinSub($responses, 'customer_responses', 'customer_responses.actor_user_id', '=', 'cohort_members.actor_user_id')
            ->leftJoinSub($invitations, 'customer_invitations', 'customer_invitations.actor_user_id', '=', 'cohort_members.actor_user_id')
            ->select([...$columns, 'customer_responses.latest_at'])
            ->selectRaw('COALESCE(customer_responses.response_count, 0) as response_count')
            ->addSelect('customer_invitations.invitation_count', 'customer_invitations.shown_count');
        if (isset($filters['actor_user_id'])) {
            $query->where('cohort_members.actor_user_id', $filters['actor_user_id']);
        }
        if ($user->can('feedback.read')) {
            $latest = ReportFilters::query($filters)->whereColumn('actor_user_id', 'cohort_members.actor_user_id')
                ->orderByDesc('received_at')->orderByDesc('id')->limit(1)->select('id');
            $query->selectSub($latest, 'latest_response_id')
                ->with(['latestFilteredResponse.answers.question', 'latestFilteredResponse.answers.options', 'latestFilteredResponse.context', 'latestFilteredResponse.version.survey']);
        }

        return $this->withReportedIdentity($query, $user);
    }

    private function withReportedIdentity(Builder $query, User $user): Builder
    {
        if (! $user->can('customer_identity.read')) {
            return $query;
        }
        foreach (['display_name' => 'customer_name', 'phone' => 'customer_phone'] as $field => $column) {
            $latest = FeedbackResponse::query()->whereColumn('actor_user_id', 'cohort_members.actor_user_id')
                ->whereNotNull($column)->orderByDesc('received_at')->orderByDesc('id')->limit(1)->select($column);
            $query->selectRaw('COALESCE(('.$latest->toSql().'), cohort_members.'.$field.') as '.$field, $latest->getBindings());
            $query->selectRaw('('.$latest->toSql().') IS NOT NULL as app_reported_'.($field === 'display_name' ? 'name' : 'phone'), $latest->getBindings());
        }

        return $query;
    }

    private function roster(User $user, ?CohortSnapshot $snapshot): Builder
    {
        $columns = ['id', 'actor_user_id', 'identity_complete', 'cohort_snapshot_id'];
        if ($user->can('customer_identity.read')) {
            $columns = [...$columns, 'display_name', 'phone'];
        }
        $members = DB::table('cohort_members')->where('cohort_snapshot_id', $snapshot?->id)->select($columns);
        $respondents = FeedbackResponse::query()
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cohort_members')
                ->where('cohort_snapshot_id', $snapshot?->id)
                ->whereColumn('cohort_members.actor_user_id', 'feedback_responses.actor_user_id'))
            ->selectRaw("CONCAT('feedback:', MIN(id)) as id, actor_user_id, 0 as identity_complete, NULL as cohort_snapshot_id")
            ->groupBy('actor_user_id');
        if ($user->can('customer_identity.read')) {
            $respondents->selectRaw('NULL as display_name, NULL as phone');
        }

        return CohortMember::query()->fromSub($members->unionAll($respondents->toBase()), 'cohort_members');
    }

    public function member(User $user, string $id): CohortMember
    {
        Access::check($user, 'customers.read');
        if (str_starts_with($id, 'feedback:')) {
            $actor = FeedbackResponse::findOrFail(substr($id, strlen('feedback:')))->actor_user_id;
            $state = $this->snapshot($user);
            $query = $this->roster($user, $state['complete'] ?? $state['latest'])
                ->where('cohort_members.actor_user_id', $actor);
        } else {
            $query = CohortMember::query()->whereKey($id);
        }

        $query->select('cohort_members.id', 'cohort_members.cohort_snapshot_id', 'cohort_members.actor_user_id', 'cohort_members.identity_complete');

        return $this->withReportedIdentity($query, $user)->with('snapshot')->firstOrFail();
    }

    public function snapshot(User $user, string $source = 'complete'): array
    {
        Access::check($user, 'customers.read');
        abort_unless(in_array($source, ['complete', 'latest_import'], true), 422);
        $complete = CohortSnapshot::where('status', 'complete')->latest('captured_at')->first();
        $latest = CohortSnapshot::latest('captured_at')->first();
        $imported = CohortSnapshot::query()
            ->whereRaw('expected_count = (SELECT COUNT(*) FROM cohort_members WHERE cohort_members.cohort_snapshot_id = cohort_snapshots.id)')
            ->whereRaw('expected_batches = (SELECT COUNT(*) FROM cohort_batches WHERE cohort_batches.cohort_snapshot_id = cohort_snapshots.id)')
            ->latest('captured_at')->first();
        $selected = $source === 'latest_import' ? $imported : ($complete ?? $latest);

        return ['complete' => $complete, 'latest' => $latest, 'latest_import' => $imported, 'selected' => $selected,
            'incomplete' => $source === 'latest_import' ? $selected?->status !== 'complete'
                : (! $complete || ($latest && $latest->status !== 'complete' && $latest->captured_at >= $complete->captured_at))];
    }

    public function detail(User $user, CohortMember $member, array $filters, bool $includeDetails = true): array
    {
        Access::check($user, 'customers.read');
        $filters = ReportFilters::validate($filters);
        $filters['actor_user_id'] = $member->actor_user_id;
        $query = ReportFilters::query($filters);
        $latest = (clone $query)->latest('received_at')->first();
        $invitations = FeedbackInvitation::where('actor_user_id', $member->actor_user_id);
        if (isset($filters['survey_version_id'])) {
            $invitations->where('survey_version_id', $filters['survey_version_id']);
        } elseif (isset($filters['survey_id'])) {
            $invitations->whereIn('survey_version_id', SurveyVersion::where('survey_id', $filters['survey_id'])->select('id'));
        }
        $shown = PromptEvent::whereIn('invitation_id', (clone $invitations)->select('id'))->where('event_type', 'shown')->exists();
        $result = ['actor_user_id' => $member->actor_user_id, 'snapshot_at' => $member->snapshot?->captured_at, 'response_status' => ! $member->identity_complete || $member->snapshot?->status !== 'complete' ? 'incomplete' : ($latest ? 'answered' : 'unanswered'), 'invitation_status' => ! $member->identity_complete ? 'unknown' : ($shown ? 'shown' : ((clone $invitations)->exists() ? 'eligible' : 'not_invited')), 'response_count' => (clone $query)->count(), 'latest_at' => $latest?->received_at, 'metrics' => null];
        if ($includeDetails && $user->can('analytics.read')) {
            $result['metrics'] = app(Analytics::class)->summary($user, $filters);
        }
        if ($includeDetails && $user->can('feedback.read')) {
            $result['latest_answers'] = $latest?->load('answers.question', 'answers.options')->answers->toArray();
        }
        if ($includeDetails && $user->can('customer_identity.read')) {
            $result['display_name'] = $member->display_name;
            $result['phone'] = $member->phone;
        }

        return $result;
    }
}
