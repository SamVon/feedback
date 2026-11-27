<?php

namespace App\Application;

use App\Http\Requests\SubmitFeedbackRequest;
use App\Models\DailyFeedbackLimit;
use App\Models\FeedbackAnswer;
use App\Models\FeedbackInvitation;
use App\Models\FeedbackResponse;
use App\Models\RuleVersion;
use App\Models\SurveyVersion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SubmitFeedback
{
    public function execute(string $caller, string $key, array $data, array $customerProfile = []): array
    {
        $payload = $data;

        return app(Idempotency::class)->run($caller, 'responses.create', $key, $payload, function () use ($caller, $payload, $customerProfile) {
            // Resolve an existing key before validation, including after future schema changes.
            $data = Validator::make($payload, (new SubmitFeedbackRequest)->rules())->validate();
            Idempotency::lockActor($data['actor_user_id']);
            $existing = FeedbackResponse::where('caller', $caller)->where('actor_user_id', $data['actor_user_id'])->where('client_submission_id', $data['client_submission_id'])->first();
            if ($existing) {
                abort_unless($existing->payload_hash === Idempotency::hash($payload), 409, 'Submission ID conflict.');

                return [$this->receipt($existing), 200];
            }
            $version = SurveyVersion::with('questions.options')->where('status', 'published')->findOrFail($data['survey_version_id']);
            $rules = RuleVersion::where('status', 'published')->orderByDesc('version')->firstOrFail()->rules;
            abort_unless($rules['channels'][$data['source']] ?? false, 422, 'Channel disabled.');
            abort_unless(ChannelPolicy::surveySupports($version, $data['source']), 422, 'Survey not available for this channel.');
            abort_if($data['source'] === 'always_on' && ! ChannelPolicy::feedbackPageEnabled($rules), 422, 'Customer Service feedback is closed.');
            $version->setRelation('questions', SurveyPresentation::questions($version, $data['source']));
            $receivedAt = now();
            $invitation = null;
            if ($data['source'] === 'auto_prompt') {
                $invitation = FeedbackInvitation::where('caller', $caller)->where('actor_user_id', $data['actor_user_id'])->where('survey_version_id', $version->id)->lockForUpdate()->find($data['invitation_id'] ?? '');
                abort_unless($invitation && $invitation->expires_at->isFuture(), 422, 'Valid owned invitation required.');
                abort_if(FeedbackResponse::where('invitation_id', $invitation->id)->exists(), 409, 'Invitation already answered.');
                $popupState = app(PopupPolicy::class)->state($data['actor_user_id'], $invitation->context->transaction_type, $rules);
                abort_unless($popupState['eligible'], 422, 'Pop-up submission denied: '.$popupState['reason']);
            } else {
                abort_if(! empty($data['invitation_id']), 422, 'Always-on cannot carry an invitation.');

                $dailyLimit = DailyFeedbackLimit::current()?->response_limit;
                if ($dailyLimit !== null) {
                    $day = $receivedAt->copy()->setTimezone('Asia/Vientiane')->startOfDay();
                    $countToday = FeedbackResponse::where('actor_user_id', $data['actor_user_id'])->where('source', 'always_on')
                        ->where('received_at', '>=', $day->copy()->utc())->count();
                    $secondsUntilMidnight = (int) ceil($receivedAt->diffInSeconds($day->addDay()));
                    abort_if($countToday >= $dailyLimit, 429, 'Daily feedback limit reached.', ['Retry-After' => (string) $secondsUntilMidnight]);
                }
            }
            if ($data['source'] !== 'auto_prompt' || ! PopupPolicy::unlimitedForTesting()) {
                $window = $rules['rate_window_seconds'];
                $recent = FeedbackResponse::where('actor_user_id', $data['actor_user_id'])->where('source', $data['source'])->where('received_at', '>=', now()->subSeconds($window));
                if ($invitation) {
                    $recent->whereHas('context', fn ($query) => $query->where('transaction_type', $invitation->context->transaction_type));
                }
                $count = $recent->count();
                abort_if($count >= $rules['rate_limit'], 429, 'Submission rate limit reached.', ['Retry-After' => (string) $window]);
            }
            $answers = collect($data['answers'])->keyBy('question_id');
            abort_if($answers->keys()->diff($version->questions->pluck('id'))->isNotEmpty(), 422, 'Question not in survey version.');
            foreach ($version->questions as $question) {
                $answer = $answers->get($question->id);
                if (! $answer) {
                    if ($question->required) {
                        throw ValidationException::withMessages(['answers' => "Required question: {$question->code}"]);
                    }

                    continue;
                }
                $field = ['rating' => 'rating_value', 'text' => 'text_value', 'choice' => 'option_ids'][$question->type];
                $withReasons = $question->type === 'rating' && $question->rating_reasons_enabled;
                $allowedFields = $withReasons ? ['question_id', 'rating_value', 'option_ids'] : ['question_id', $field];
                $withComment = $question->type === 'rating';
                if ($withComment) {
                    $allowedFields[] = 'text_value';
                    abort_if(mb_strlen($answer['text_value'] ?? '') > $question->max_length, 422, 'Rating comment is too long.');
                }
                abort_unless(array_key_exists($field, $answer) && array_diff(array_keys($answer), $allowedFields) === [], 422, 'Answer type mismatch.');
                if ($question->type === 'rating') {
                    abort_unless(is_int($answer[$field]) && $answer[$field] >= 1 && $answer[$field] <= 5, 422, 'Rating must be an integer 1–5.');
                }
                if ($withReasons) {
                    $selected = $answer['option_ids'] ?? [];
                    $valid = $question->options->where('rating_value', $answer['rating_value'])->pluck('id');
                    abort_unless(count($selected) <= $question->max_choices && collect($selected)->diff($valid)->isEmpty(), 422, 'Reasons must belong to the selected rating and question.');
                    abort_if($question->rating_reasons_required && count($selected) === 0, 422, 'Select at least one rating reason.');
                }
                if ($question->type === 'text') {
                    abort_unless(is_string($answer[$field]) && mb_strlen($answer[$field]) <= $question->max_length && trim($answer[$field]) !== '', 422, 'Text length invalid.');
                }
                if ($question->type === 'choice') {
                    abort_unless(count($answer[$field]) >= 1 && count($answer[$field]) <= $question->max_choices && count(array_unique($answer[$field])) === count($answer[$field]) && collect($answer[$field])->diff($question->options->pluck('id'))->isEmpty(), 422, 'Invalid choice.');
                }
            }
            $response = FeedbackResponse::create([
                'caller' => $caller, 'client_submission_id' => $data['client_submission_id'], 'payload_hash' => Idempotency::hash($payload),
                'actor_user_id' => $data['actor_user_id'], 'one_id' => $data['one_id'] ?? null, 'survey_version_id' => $version->id, 'source' => $data['source'],
                'customer_name' => $customerProfile['name'] ?? null, 'customer_phone' => $customerProfile['phone'] ?? null,
                'gender' => $data['gender'] ?? null,
                'invitation_id' => $invitation?->id, 'transaction_context_id' => $invitation?->transaction_context_id, 'submitted_at' => $data['submitted_at'], 'received_at' => $receivedAt,
                'app_version' => $data['metadata']['app_version'] ?? null, 'os' => $data['metadata']['os'] ?? null,
            ]);
            foreach ($data['answers'] as $answer) {
                $stored = FeedbackAnswer::create(['feedback_response_id' => $response->id, 'question_id' => $answer['question_id'], 'rating_value' => $answer['rating_value'] ?? null, 'text_value' => $answer['text_value'] ?? null]);
                if (isset($answer['option_ids'])) {
                    $stored->options()->attach($answer['option_ids']);
                }
            }

            return [$this->receipt($response), 201];
        });
    }

    private function receipt(FeedbackResponse $response): array
    {
        return ['response_id' => $response->id, 'client_submission_id' => $response->client_submission_id, 'status' => 'stored', 'received_at' => $response->received_at->utc()->format('Y-m-d\TH:i:s\Z')];
    }
}
