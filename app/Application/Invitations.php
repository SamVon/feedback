<?php

namespace App\Application;

use App\Models\FeedbackInvitation;
use App\Models\FeedbackResponse;
use App\Models\PromptEvent;
use App\Models\RuleVersion;
use App\Models\SurveyVersion;
use App\Models\TransactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Invitations
{
    public function evaluate(string $caller, string $key, array $data): array
    {
        $data = Validator::make($data, [
            'actor_user_id' => 'required|string|max:100', 'survey_version_id' => 'required|uuid',
            'transaction_context' => 'required|array:transaction_id,transaction_type,transaction_occurred_at,verified_at,success_verified',
            'transaction_context.transaction_id' => 'required|string|max:100', 'transaction_context.transaction_type' => 'required|string|max:50',
            'transaction_context.transaction_occurred_at' => 'required|date_format:Y-m-d\TH:i:s\Z|before_or_equal:now',
            'transaction_context.verified_at' => 'required|date_format:Y-m-d\TH:i:s\Z|before_or_equal:now|after_or_equal:transaction_context.transaction_occurred_at',
            'transaction_context.success_verified' => 'required|accepted',
        ])->validate();

        return DB::transaction(function () use ($caller, $key, $data): array {
            Idempotency::lockActor($data['actor_user_id']);
            $result = app(Idempotency::class)->run($caller, 'invitations.create', $key, $data, function () use ($caller, $data): array {
                Idempotency::lockActor('__transaction:'.hash('sha256', $caller.':'.$data['transaction_context']['transaction_id']));
                $version = SurveyVersion::where('status', 'published')->findOrFail($data['survey_version_id']);
                abort_unless(ChannelPolicy::surveySupports($version, 'auto_prompt'), 422, 'Survey not available for auto prompt.');
                abort_if(SurveyPresentation::questions($version, 'auto_prompt')->isEmpty(), 422, 'No enabled rating questions for auto prompt.');
                $rule = RuleVersion::where('status', 'published')->orderByDesc('version')->firstOrFail();
                $contextData = $data['transaction_context'];
                unset($contextData['success_verified']);
                $context = TransactionContext::where('caller', $caller)->where('transaction_id', $contextData['transaction_id'])->first();
                if ($context) {
                    abort_unless($context->actor_user_id === $data['actor_user_id'] && $context->transaction_type === $contextData['transaction_type'] && $context->transaction_occurred_at->equalTo($contextData['transaction_occurred_at']), 409, 'Transaction context conflict.');
                }
                $state = app(PopupPolicy::class)->state($data['actor_user_id'], $contextData['transaction_type'], $rule->rules);
                if (! $state['eligible']) {
                    return [$state, 200];
                }
                if ($context) {
                    $existing = FeedbackInvitation::where('transaction_context_id', $context->id)->where('survey_version_id', $version->id)->first();
                    if ($existing) {
                        return [$this->receipt($existing), 200];
                    }
                }
                $context ??= TransactionContext::create($contextData + ['caller' => $caller, 'actor_user_id' => $data['actor_user_id']]);
                $invitation = FeedbackInvitation::create(['caller' => $caller, 'actor_user_id' => $data['actor_user_id'], 'survey_version_id' => $version->id, 'rule_version_id' => $rule->id, 'transaction_context_id' => $context->id, 'eligible_at' => now(), 'expires_at' => now()->addSeconds($rule->rules['invitation_ttl_seconds'])]);

                return [$this->receipt($invitation), 201];
            });

            // Invitation receipts are idempotent, but permission to display is always evaluated now.
            $rules = RuleVersion::where('status', 'published')->orderByDesc('version')->firstOrFail()->rules;
            $state = app(PopupPolicy::class)->state($data['actor_user_id'], $data['transaction_context']['transaction_type'], $rules);
            if (! $state['eligible']) {
                return [$state, 200];
            }
            [$receipt, $status] = $result;
            if (isset($receipt['invitation_id'])) {
                $invitation = FeedbackInvitation::findOrFail($receipt['invitation_id']);
                if (FeedbackResponse::where('invitation_id', $invitation->id)->exists()) {
                    return [array_replace($state, ['eligible' => false, 'reason' => 'invitation_answered']), 200];
                }

                return [array_replace($state, $this->receipt($invitation)), $status];
            }

            // A previously denied evaluation has no invitation; a new attempt needs a new key.
            return [array_replace($receipt, $state, ['eligible' => false, 'reason' => 'evaluation_retry_requires_new_key']), $status];
        }, 5);
    }

    private function receipt(FeedbackInvitation $invitation): array
    {
        return ['eligible' => $invitation->expires_at->isFuture(), 'reason' => $invitation->expires_at->isFuture() ? null : 'invitation_expired', 'invitation_id' => $invitation->id, 'survey_version_id' => $invitation->survey_version_id, 'expires_at' => $invitation->expires_at->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    public function event(string $caller, string $key, array $data): array
    {
        $data = Validator::make($data, ['actor_user_id' => 'required|string|max:100', 'invitation_id' => 'required|uuid', 'event_id' => 'required|string|max:100', 'event_type' => 'required|in:shown,dismissed', 'occurred_at' => 'required|date_format:Y-m-d\TH:i:s\Z|before_or_equal:now'])->validate();

        return app(Idempotency::class)->run($caller, 'prompt-events.create', $key, $data, function () use ($caller, $data) {
            Idempotency::lockActor('__event:'.hash('sha256', $caller.':'.$data['event_id']));
            $invitation = FeedbackInvitation::where('caller', $caller)->where('actor_user_id', $data['actor_user_id'])->lockForUpdate()->findOrFail($data['invitation_id']);
            abort_unless($invitation->eligible_at->lte($data['occurred_at']) && $invitation->expires_at->gte($data['occurred_at']), 422, 'Event outside invitation lifetime.');
            $event = PromptEvent::where('caller', $caller)->where('event_id', $data['event_id'])->first();
            if ($event) {
                abort_unless($event->payload_hash === Idempotency::hash($data), 409, 'Event ID conflict.');

                return [['event_id' => $event->event_id, 'status' => 'stored'], 200];
            }
            PromptEvent::create(collect($data)->except('actor_user_id')->all() + ['caller' => $caller, 'received_at' => now(), 'payload_hash' => Idempotency::hash($data)]);

            return [['event_id' => $data['event_id'], 'status' => 'stored'], 201];
        });
    }
}
