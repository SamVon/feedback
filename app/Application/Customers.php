<?php

namespace App\Application;

use App\Models\CohortMember;
use App\Models\CohortSnapshot;
use App\Models\FeedbackInvitation;
use App\Models\PromptEvent;
use App\Models\SurveyVersion;
use App\Models\User;

final class Customers
{
    public function snapshot(User $user): array
    {
        Access::check($user, 'customers.read');
        $complete = CohortSnapshot::where('status', 'complete')->latest('captured_at')->first();
        $latest = CohortSnapshot::latest('captured_at')->first();

        return ['complete' => $complete, 'latest' => $latest, 'incomplete' => ! $complete || ($latest && $latest->status !== 'complete' && $latest->captured_at >= $complete->captured_at)];
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
        $result = ['actor_user_id' => $member->actor_user_id, 'snapshot_at' => $member->snapshot->captured_at, 'response_status' => ! $member->identity_complete || $member->snapshot->status !== 'complete' ? 'incomplete' : ($latest ? 'answered' : 'unanswered'), 'invitation_status' => ! $member->identity_complete ? 'unknown' : ($shown ? 'shown' : ((clone $invitations)->exists() ? 'eligible' : 'not_invited')), 'response_count' => (clone $query)->count(), 'latest_at' => $latest?->received_at, 'metrics' => null];
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
