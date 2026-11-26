<?php

namespace Tests\Feature;

use App\Application\Changes;
use App\Application\ChannelPolicy;
use App\Filament\Resources\Pages\ListCustomerServices;
use App\Models\RuleVersion;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;

class CustomerFeedbackApiTest extends FeedbackTest
{
    public function test_customer_service_switch_controls_mobile_config_and_new_answers_after_publication(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $this->actingAs($this->staff);
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $saved = $this->withHeaders($headers + ['Idempotency-Key' => 'before-service-close'])
            ->postJson('/api/v1/feedback/responses', $this->payload('before-service-close'))->assertCreated()->json();
        $published = $this->rule;
        foreach ([false, true] as $enabled) {
            $service = ChannelPolicy::customerService($published->rules);
            $service['enabled'] = $enabled;
            Livewire::test(ListCustomerServices::class)->callTableAction('newVersion', $published,
                data: ['revision' => $published->revision, 'customer_service' => $service])->assertHasNoActionErrors();
            $draft = RuleVersion::where('status', 'draft')->latest('version')->firstOrFail();
            $this->assertSame($enabled, $draft->rules['channels']['always_on']);
            $this->withHeaders($headers)->getJson('/api/v1/feedback/config?source=always_on')->assertOk()
                ->assertJsonPath('channels.always_on', ! $enabled)->assertJsonPath('customer_service.enabled', ! $enabled);
            Livewire::test(ListCustomerServices::class)->callTableAction('publish', $draft,
                data: ['revision' => $draft->revision])->assertHasNoActionErrors();
            foreach (['always_on', 'auto_prompt'] as $source) {
                $config = $this->withHeaders($headers)->getJson('/api/v1/feedback/config?source='.$source)->assertOk()
                    ->assertJsonPath('channels.always_on', $enabled)->assertJsonPath('customer_service.enabled', $enabled)
                    ->assertJsonPath('channels.auto_prompt', true);
                $this->assertStringContainsString('no-store', $config->headers->get('Cache-Control'));
                foreach ($config->json('surveys') ?? [] as $survey) {
                    $this->assertSame($enabled, $survey['channels']['always_on']);
                    $this->assertSame($enabled, $survey['customer_service']['enabled']);
                }
            }
            $submission = $enabled ? 'after-service-reopen' : 'after-service-close';
            $response = $this->withHeaders($headers + ['Idempotency-Key' => $submission])
                ->postJson('/api/v1/feedback/responses', $this->payload($submission));
            if ($enabled) {
                $response->assertCreated();
            } else {
                $response->assertUnprocessable();
                $this->withHeaders($headers + ['Idempotency-Key' => 'before-service-close'])
                    ->postJson('/api/v1/feedback/responses', $this->payload('before-service-close'))->assertOk()->assertExactJson($saved);
                $this->withHeaders($headers + ['Idempotency-Key' => 'popup-service-closed'])->postJson('/api/v1/feedback/eligibility', [
                    'survey_version_id' => $this->survey->id, 'transaction_id' => 'popup-service-closed',
                    'transaction_type' => 'BUY_GOLD', 'transaction_occurred_at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
                ])->assertCreated()->assertJsonPath('eligible', true);
            }
            $published = $draft->fresh();
        }
        $this->assertDatabaseCount('feedback_responses', 2);
    }

    public function test_customer_service_switch_reopens_a_legacy_disabled_feedback_channel(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $rules = $this->rule->rules;
        $rules['channels']['always_on'] = false;
        $rules['customer_service']['enabled'] = true;
        $legacy = app(Changes::class)->ruleDraft($this->staff, $rules);
        app(Changes::class)->transition($this->staff, 'rules', $legacy->id, $legacy->revision, 'publish');
        $this->withToken($this->token())->getJson('/api/v1/feedback/config?source=always_on')->assertOk()
            ->assertJsonPath('channels.always_on', false)->assertJsonPath('customer_service.enabled', false);
        $this->actingAs($this->staff);
        Livewire::test(ListCustomerServices::class)->mountTableAction('newVersion', $legacy)
            ->assertTableActionDataSet(['customer_service.enabled' => false])
            ->setTableActionData(['customer_service.enabled' => true])->callMountedTableAction()->assertHasNoActionErrors();
        $draft = RuleVersion::where('status', 'draft')->latest('version')->firstOrFail();
        Livewire::test(ListCustomerServices::class)->callTableAction('publish', $draft,
            data: ['revision' => $draft->revision])->assertHasNoActionErrors();
        $this->withToken($this->token())->getJson('/api/v1/feedback/config?source=always_on')->assertOk()
            ->assertJsonPath('channels.always_on', true)->assertJsonPath('customer_service.enabled', true);
        $this->withHeaders(['Idempotency-Key' => 'legacy-reopened'])->postJson('/api/v1/feedback/responses', $this->payload())->assertCreated();
    }

    public function test_always_on_config_groups_all_published_surveys_for_separate_submissions(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $draft = $this->draft();
        $draft['code'] = 'second-survey';
        $second = app(Changes::class)->surveyDraft($this->staff, $draft);
        app(Changes::class)->transition($this->staff, 'survey', $second->id, 1, 'publish');
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $config = $this->withHeaders($headers)->getJson('/api/v1/feedback/config?source=always_on')->assertOk()->json();
        $this->assertSame([5, 4, 3, 2, 1], $config['rating_levels']);
        $surveys = $config['surveys'];
        $this->assertCount(2, $surveys);
        $this->assertEqualsCanonicalizing([$this->survey->id, $second->id], array_column($surveys, 'survey_version_id'));
        foreach ($surveys as $index => $survey) {
            $this->assertArrayNotHasKey('rating_levels', $survey);
            $rating = collect($survey['questions'])->firstWhere('type', 'rating');
            $this->assertSame(['auto_prompt', 'always_on'], $rating['comment']['channels']);
            $payload = ['client_submission_id' => 'group-'.$index, 'survey_version_id' => $survey['survey_version_id'], 'source' => 'always_on', 'submitted_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'answers' => [['question_id' => $rating['id'], 'rating_value' => 5]]];
            $this->withHeaders($headers + ['Idempotency-Key' => 'group-key-'.$index])->postJson('/api/v1/feedback/responses', $payload)->assertCreated();
        }
    }

    public function test_config_exposes_rating_comment_for_popup_and_feedback_page(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $this->withToken($this->token())->getJson('/api/v1/feedback/config?source=auto_prompt')->assertOk()
            ->assertJsonPath('questions.0.comment.channels', ['auto_prompt', 'always_on'])
            ->assertJsonPath('questions.0.comment.field', 'text_value');

        foreach (['', '?source=always_on', '?source=always_on&survey_code=experience'] as $query) {
            $this->getJson('/api/v1/feedback/config'.$query)->assertOk()
                ->assertJsonPath('source', 'always_on')
                ->assertJsonPath('questions.0.type', 'rating')
                ->assertJsonPath('questions.0.comment.channels', ['auto_prompt', 'always_on'])
                ->assertJsonPath('questions.1.type', 'text');
        }
    }

    public function test_feedback_page_stores_rating_comment_and_text_answer_and_replays_once(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $this->withToken($this->token());
        $payload = $this->payload('page-comment');
        $payload['answers'][0]['text_value'] = str_repeat('ກ', 100);
        $payload['answers'][] = ['question_id' => $this->survey->questions()->where('type', 'text')->firstOrFail()->id, 'text_value' => 'Separate text answer'];

        $receipt = $this->withHeader('Idempotency-Key', 'page-comment')->postJson('/api/v1/feedback/responses', $payload)->assertCreated()->json();
        $this->postJson('/api/v1/feedback/responses', $payload)->assertOk()->assertExactJson($receipt);
        $this->assertDatabaseCount('feedback_responses', 1);
        $this->assertDatabaseCount('feedback_answers', 2);
        $this->assertDatabaseHas('feedback_answers', ['feedback_response_id' => $receipt['response_id'], 'rating_value' => 5, 'text_value' => str_repeat('ກ', 100)]);
        $this->assertDatabaseHas('feedback_answers', ['feedback_response_id' => $receipt['response_id'], 'text_value' => 'Separate text answer']);

        $payload['answers'][0]['text_value'] = 'Changed comment';
        $this->postJson('/api/v1/feedback/responses', $payload)->assertConflict();
        $this->assertDatabaseCount('feedback_answers', 2);
    }

    #[TestWith([101])]
    #[TestWith([['invalid']])]
    public function test_feedback_page_rejects_invalid_rating_comment_without_saving(mixed $value): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $payload = $this->payload('invalid-page-comment');
        $payload['answers'][0]['text_value'] = is_int($value) ? str_repeat('ກ', $value) : $value;

        $this->withToken($this->token())->withHeader('Idempotency-Key', 'invalid-page-comment')
            ->postJson('/api/v1/feedback/responses', $payload)->assertUnprocessable();

        $this->assertDatabaseCount('feedback_responses', 0);
        $this->assertDatabaseCount('feedback_answers', 0);
    }

    public function test_publisher_can_publish_draft_and_submitted_survey_without_approval(): void
    {
        $draft = $this->draft();
        $draft['code'] = 'direct-publish';
        $version = app(Changes::class)->surveyDraft($this->staff, $draft);
        app(Changes::class)->transition($this->staff, 'survey', $version->id, 1, 'publish');
        $this->assertSame('published', $version->fresh()->status);
        $this->assertDatabaseHas('change_requests', ['entity_id' => $version->id, 'status' => 'published']);

        $draft['code'] = 'submitted-publish';
        $submitted = app(Changes::class)->surveyDraft($this->staff, $draft);
        app(Changes::class)->transition($this->staff, 'survey', $submitted->id, 1, 'submit');
        app(Changes::class)->transition($this->staff, 'survey', $submitted->id, 1, 'publish');
        $this->assertSame('published', $submitted->fresh()->status);
    }

    public function test_config_without_survey_code_uses_latest_created_published_survey(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $this->survey->survey->update(['created_at' => now()->subDay()]);
        $draft = $this->draft();
        $draft['code'] = 'newer-survey';
        $newer = app(Changes::class)->surveyDraft($this->staff, $draft);
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $url = '/api/v1/feedback/config?source=auto_prompt';
        $this->withHeaders($headers)->getJson($url)->assertOk()->assertJsonPath('survey_code', 'experience');
        foreach (['submit', 'approve', 'publish'] as $action) {
            app(Changes::class)->transition($this->staff, 'survey', $newer->id, 1, $action);
        }
        $this->withHeaders($headers)->getJson($url)->assertOk()->assertJsonPath('survey_code', 'newer-survey');
        $this->withHeaders($headers)->getJson($url.'&survey_code=experience')->assertOk()->assertJsonPath('survey_code', 'experience');
    }

    private function token(array $overrides = [], string $secret = 'test-customer-secret'): string
    {
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode(array_replace(['_id' => 'customer-1', 'role' => 'CUSTOMER', 'exp' => time() + 3600], $overrides));

        return $header.'.'.$payload.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true)), '+/', '-_'), '=');
    }

    public function test_customer_token_authentication(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $url = '/api/v1/feedback/config?survey_code=experience&source=always_on';
        $this->getJson($url)->assertUnauthorized();
        $this->withToken($this->token([], 'wrong-secret'))->getJson($url)->assertUnauthorized();
        $this->withToken($this->token(['exp' => time() - 1]))->getJson($url)->assertUnauthorized();
        $this->withToken($this->token(['role' => 'ADMIN']))->getJson($url)->assertForbidden();
        $this->withToken($this->token())->getJson($url)->assertOk()->assertJsonPath('survey_version_id', $this->survey->id);
    }

    public function test_customer_direct_popup_flow_records_client_report_and_jwt_actor(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $headers = ['Authorization' => 'Bearer '.$this->token(), 'Idempotency-Key' => 'eligibility-1'];
        $transaction = ['survey_version_id' => $this->survey->id, 'transaction_id' => 'txn-1', 'transaction_type' => 'BUY_GOLD', 'transaction_occurred_at' => now()->subMinutes(1)->utc()->format('Y-m-d\TH:i:s\Z'), 'actor_user_id' => 'forged'];
        $invitation = $this->withHeaders($headers)->postJson('/api/v1/feedback/eligibility', $transaction)->assertCreated()->assertJsonPath('eligible', true)->json('invitation_id');
        $this->assertDatabaseHas('transaction_contexts', ['transaction_id' => 'txn-1', 'actor_user_id' => 'customer-1', 'verification_source' => 'client_reported', 'verified_at' => null]);
        $answer = $this->payload('submission-1', 'forged');
        $answer['source'] = 'auto_prompt';
        $answer['invitation_id'] = $invitation;
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token(), 'Idempotency-Key' => 'response-1'])->postJson('/api/v1/feedback/responses', $answer)->assertCreated();
        $this->assertDatabaseHas('feedback_responses', ['actor_user_id' => 'customer-1', 'invitation_id' => $invitation]);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token(['_id' => 'customer-2']), 'Idempotency-Key' => 'response-2'])->postJson('/api/v1/feedback/responses', $answer)->assertUnprocessable();
    }

    public function test_unlimited_popup_testing_allows_buy_sale_and_withdraw_but_preserves_owned_single_response(): void
    {
        $this->freezeTime();
        config(['feedback.customer_jwt_secret' => 'test-customer-secret', 'feedback.test_unlimited_popup' => true]);
        $rules = $this->rule->rules;
        $rules['transaction_rules'][] = array_replace($rules['transaction_rules'][0], ['transaction_type' => 'SALE_GOLD']);
        $rules['transaction_rules'][] = array_replace($rules['transaction_rules'][0], ['transaction_type' => 'WITHDRAW_GOLD']);
        $version = app(Changes::class)->ruleDraft($this->staff, $rules);
        app(Changes::class)->transition($this->staff, 'rules', $version->id, 1, 'publish');
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $version = $this->withHeaders($headers)->getJson('/api/v1/feedback/config?source=auto_prompt')
            ->assertOk()->assertJsonPath('channels.auto_prompt', true)->json('survey_version_id');
        foreach (['BUY_GOLD', 'SALE_GOLD', 'WITHDRAW_GOLD'] as $type) {
            for ($index = 0; $index < 7; $index++) {
                $key = $type.'-'.$index;
                $transaction = ['survey_version_id' => $version, 'transaction_id' => $key, 'transaction_type' => $type,
                    'transaction_occurred_at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z')];
                $invitation = $this->withHeaders($headers + ['Idempotency-Key' => 'eligibility-'.$key])
                    ->postJson('/api/v1/feedback/eligibility', $transaction)->assertCreated()
                    ->assertJsonPath('eligible', true)->assertJsonPath('successful_response_count', $index)
                    ->assertJsonPath('max_responses', null)->assertJsonPath('cooldown_until', null)
                    ->assertJsonPath('cooldown_days', 0)->assertJsonPath('limits_enforced', false)->json('invitation_id');
                $answer = $this->payload('answer-'.$key);
                $answer['source'] = 'auto_prompt';
                $answer['invitation_id'] = $invitation;
                $receipt = $this->withHeaders($headers + ['Idempotency-Key' => 'submit-'.$key])
                    ->postJson('/api/v1/feedback/responses', $answer)->assertCreated()->json();
                if ($index === 0) {
                    $this->withHeaders($headers + ['Idempotency-Key' => 'submit-'.$key])
                        ->postJson('/api/v1/feedback/responses', $answer)->assertOk()->assertExactJson($receipt);
                    $this->withHeaders($headers + ['Idempotency-Key' => 'eligibility-'.$key])
                        ->postJson('/api/v1/feedback/eligibility', $transaction)->assertOk()->assertJsonPath('reason', 'invitation_answered');
                    $answer['client_submission_id'] = 'duplicate-'.$key;
                    $this->withHeaders($headers + ['Idempotency-Key' => 'duplicate-'.$key])
                        ->postJson('/api/v1/feedback/responses', $answer)->assertConflict();
                    $this->withHeaders(['Authorization' => 'Bearer '.$this->token(['_id' => 'customer-2']), 'Idempotency-Key' => 'foreign-'.$key])
                        ->postJson('/api/v1/feedback/responses', $answer)->assertUnprocessable();
                }
            }
        }
        $this->assertDatabaseCount('feedback_responses', 21);
        $this->assertDatabaseCount('feedback_invitations', 21);

        config(['feedback.test_unlimited_popup' => false]);
        $transaction['transaction_id'] = 'limits-restored';
        $this->withHeaders($headers + ['Idempotency-Key' => 'limits-restored'])
            ->postJson('/api/v1/feedback/eligibility', $transaction)->assertOk()
            ->assertJsonPath('eligible', false)->assertJsonPath('reason', 'response_limit_reached')
            ->assertJsonPath('limits_enforced', true)->assertJsonPath('successful_response_count', 7);
    }

    public function test_popup_testing_switch_preserves_always_on_submission_limits(): void
    {
        $this->freezeTime();
        config(['feedback.customer_jwt_secret' => 'test-customer-secret', 'feedback.test_unlimited_popup' => true]);
        for ($index = 0; $index < 5; $index++) {
            $this->withHeaders(['Authorization' => 'Bearer '.$this->token(), 'Idempotency-Key' => 'always-limited-'.$index])
                ->postJson('/api/v1/feedback/responses', $this->payload('always-limited-'.$index))->assertCreated();
        }
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token(), 'Idempotency-Key' => 'always-over-limit'])
            ->postJson('/api/v1/feedback/responses', $this->payload('always-over-limit'))->assertTooManyRequests();
        $this->assertDatabaseCount('feedback_responses', 5);
    }

    public function test_customer_prompt_events_are_owned_and_idempotent(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $headers = ['Authorization' => 'Bearer '.$this->token(), 'Idempotency-Key' => 'eligibility-event'];
        $transaction = ['survey_version_id' => $this->survey->id, 'transaction_id' => 'txn-event', 'transaction_type' => 'BUY_GOLD', 'transaction_occurred_at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z')];
        $invitation = $this->withHeaders($headers)->postJson('/api/v1/feedback/eligibility', $transaction)->assertCreated()->json('invitation_id');
        $event = ['invitation_id' => $invitation, 'event_id' => 'event-1', 'event_type' => 'shown', 'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')];
        $headers['Idempotency-Key'] = 'event-key';
        $this->withHeaders($headers)->postJson('/api/v1/feedback/prompt-events', $event)->assertCreated()->assertJsonPath('status', 'stored');
        $this->withHeaders($headers)->postJson('/api/v1/feedback/prompt-events', $event)->assertOk();
        $event['event_type'] = 'dismissed';
        $this->withHeaders($headers)->postJson('/api/v1/feedback/prompt-events', $event)->assertConflict();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token(['_id' => 'customer-2']), 'Idempotency-Key' => 'other-event'])->postJson('/api/v1/feedback/prompt-events', $event)->assertNotFound();
    }

    public function test_customer_always_on_response_is_owned_and_idempotent(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $payload = $this->payload('always-on-1', 'forged');
        $headers = ['Authorization' => 'Bearer '.$this->token(), 'Idempotency-Key' => 'always-on-key'];
        $first = $this->withHeaders($headers)->postJson('/api/v1/feedback/responses', $payload)->assertCreated()->json();
        $this->withHeaders($headers)->postJson('/api/v1/feedback/responses', $payload)->assertOk()->assertExactJson($first);
        $this->assertDatabaseHas('feedback_responses', ['id' => $first['response_id'], 'actor_user_id' => 'customer-1', 'source' => 'always_on']);
        $payload['answers'][0]['rating_value'] = 3;
        $this->withHeaders($headers)->postJson('/api/v1/feedback/responses', $payload)->assertConflict();
    }
}
