<?php

namespace Tests\Feature;

use App\Application\Changes;
use App\Application\Idempotency;
use App\Application\PopupPolicy;
use App\Filament\Resources\Pages\ListRules;
use App\Models\FeedbackAnswer;
use App\Models\RuleVersion;
use App\Models\SurveyVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PopupPolicyTest extends TestCase
{
    use RefreshDatabase;

    private SurveyVersion $survey;

    private User $staff;

    private function prepare(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01T12:00:00Z'));
        config(['feedback.staff_auth' => 'local', 'feedback.services.app-backend.token_hash' => hash('sha256', 'popup-test')]);
        $this->staff = User::factory()->create(['active' => true, 'permissions' => config('feedback.permissions')]);
        $changes = app(Changes::class);
        $this->survey = $changes->surveyDraft($this->staff, ['code' => 'popup-test', 'name' => 'Popup test', 'questions' => [
            ['code' => 'overall', 'type' => 'rating', 'label' => ['en' => 'Overall', 'lo' => 'ຄະແນນ'], 'required' => true, 'max_length' => 100, 'max_choices' => 1, 'options' => []],
        ]]);
        $rule = $changes->ruleDraft($this->staff, $this->rules());
        foreach ([['survey', $this->survey], ['rules', $rule]] as [$type, $version]) {
            foreach (['submit', 'approve', 'publish'] as $action) {
                $changes->transition($this->staff, $type, $version->id, 1, $action);
            }
        }
    }

    private function rules(): array
    {
        return ['channels' => ['auto_prompt' => true, 'always_on' => true], 'transaction_rules' => [
            ['transaction_type' => 'BUY_GOLD', 'enabled' => true, 'max_responses' => 3, 'cooldown_days' => 30, 'reset_policy' => 'pending_confirmation'],
            ['transaction_type' => 'SALE_GOLD', 'enabled' => true, 'max_responses' => 3, 'cooldown_days' => 30, 'reset_policy' => 'pending_confirmation'],
        ], 'rate_limit' => 5, 'rate_window_seconds' => 3600, 'invitation_ttl_seconds' => 86400];
    }

    private function apiPost(string $path, array $data, string $key): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer popup-test', 'X-Service-ID' => 'app-backend', 'Idempotency-Key' => $key])
            ->postJson('/internal/v1/feedback/'.$path, $data);
    }

    private function context(string $type, string $transaction, string $actor = 'actor-1'): array
    {
        return ['actor_user_id' => $actor, 'survey_version_id' => $this->survey->id, 'transaction_context' => [
            'transaction_id' => $transaction, 'transaction_type' => $type, 'transaction_occurred_at' => now()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'verified_at' => now()->format('Y-m-d\TH:i:s\Z'), 'success_verified' => true,
        ]];
    }

    private function eligibility(string $type, string $transaction, string $actor = 'actor-1'): TestResponse
    {
        return $this->apiPost('eligibility', $this->context($type, $transaction, $actor), 'eligibility-'.$transaction);
    }

    private function response(string $invitation, string $submission = 'submission-1'): array
    {
        return ['actor_user_id' => 'actor-1', 'client_submission_id' => $submission, 'survey_version_id' => $this->survey->id,
            'source' => 'auto_prompt', 'invitation_id' => $invitation, 'submitted_at' => now()->format('Y-m-d\TH:i:s\Z'),
            'answers' => [['question_id' => $this->survey->questions()->first()->id, 'rating_value' => 5]]];
    }

    public function test_unlimited_test_switch_preserves_disabled_triggers_schedules_and_production_limits(): void
    {
        $this->prepare();
        config(['feedback.test_unlimited_popup' => true]);
        $invitation = $this->eligibility('BUY_GOLD', 'test-buy')->assertCreated()->json('invitation_id');
        $this->apiPost('responses', $this->response($invitation), 'test-buy-answer')->assertCreated();
        $rules = $this->rules();
        $policy = app(PopupPolicy::class);
        $rules['transaction_rules'][0]['enabled'] = false;
        $this->assertSame('trigger_disabled', $policy->state('actor-1', 'BUY_GOLD', $rules)['reason']);
        $rules = $this->rules();
        $rules['channels']['auto_prompt'] = false;
        $this->assertSame('trigger_disabled', $policy->state('actor-1', 'BUY_GOLD', $rules)['reason']);
        $rules = $this->rules();
        $rules['popup_schedule'] = ['starts_at' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'), 'ends_at' => null];
        $this->assertSame('outside_schedule', $policy->state('actor-1', 'BUY_GOLD', $rules)['reason']);

        $this->app->instance('env', 'production');
        $state = $policy->state('actor-1', 'BUY_GOLD', $this->rules());
        $this->assertFalse($state['eligible']);
        $this->assertTrue($state['limits_enforced']);
        $this->assertSame('cooldown_active', $state['reason']);
        $this->assertSame(3, $state['max_responses']);
        $this->assertSame(30, $state['cooldown_days']);
    }

    public function test_stored_buy_response_starts_30_days_only_for_that_account_and_type(): void
    {
        $this->prepare();
        $invitation = $this->eligibility('BUY_GOLD', 'buy-1')->assertCreated()->json('invitation_id');
        $this->travel(1)->hour();
        $this->apiPost('responses', $this->response($invitation), 'submit')->assertCreated();

        $this->eligibility('BUY_GOLD', 'buy-2')->assertOk()->assertJsonPath('eligible', false)
            ->assertJsonPath('reason', 'cooldown_active')->assertJsonPath('successful_response_count', 1)
            ->assertJsonPath('cooldown_until', '2026-01-31T13:00:00Z');
        $sale = $this->eligibility('SALE_GOLD', 'sale-1')->assertCreated()->assertJsonPath('successful_response_count', 0)->json('invitation_id');
        $this->apiPost('responses', $this->response($sale, 'sale-answer'), 'sale-answer')->assertCreated();
        $this->eligibility('SALE_GOLD', 'sale-2')->assertJsonPath('successful_response_count', 1)->assertJsonPath('reason', 'cooldown_active');
        $this->eligibility('BUY_GOLD', 'other-buy', 'actor-2')->assertCreated()->assertJsonPath('successful_response_count', 0);
        $this->assertDatabaseCount('feedback_responses', 2);
    }

    public function test_shown_dismissed_and_invalid_answers_do_not_consume_quota(): void
    {
        $this->prepare();
        $invitation = $this->eligibility('BUY_GOLD', 'buy-1')->assertCreated()->json('invitation_id');
        foreach (['shown', 'dismissed'] as $type) {
            $this->apiPost('prompt-events', ['actor_user_id' => 'actor-1', 'invitation_id' => $invitation, 'event_id' => $type, 'event_type' => $type, 'occurred_at' => now()->format('Y-m-d\TH:i:s\Z')], $type)->assertCreated();
        }
        $bad = $this->response($invitation);
        $bad['answers'][0]['rating_value'] = 9;
        $this->apiPost('responses', $bad, 'bad')->assertUnprocessable();

        $this->eligibility('BUY_GOLD', 'buy-2')->assertCreated()->assertJsonPath('successful_response_count', 0)->assertJsonPath('cooldown_until', null);
        $this->assertDatabaseCount('feedback_responses', 0);
        $this->assertDatabaseMissing('idempotency_records', ['operation' => 'responses.create', 'key' => 'bad']);
    }

    public function test_failed_answer_write_rolls_back_receipt_quota_and_cooldown_together(): void
    {
        $this->prepare();
        $invitation = $this->eligibility('BUY_GOLD', 'buy')->assertCreated()->json('invitation_id');
        $payload = $this->response($invitation);
        $failWrite = true;
        FeedbackAnswer::creating(function () use (&$failWrite): void {
            if ($failWrite) {
                throw new \RuntimeException('Simulated answer storage failure after response insert.');
            }
        });
        $this->apiPost('responses', $payload, 'retry-after-failure')->assertStatus(500);
        $this->assertDatabaseCount('feedback_responses', 0);
        $this->assertDatabaseCount('feedback_answers', 0);
        $this->assertDatabaseMissing('idempotency_records', ['operation' => 'responses.create', 'key' => 'retry-after-failure']);
        $this->eligibility('BUY_GOLD', 'after-failure')->assertCreated()->assertJsonPath('successful_response_count', 0)->assertJsonPath('cooldown_until', null);
        $failWrite = false;
        $this->apiPost('responses', $payload, 'retry-after-failure')->assertCreated();
        $this->eligibility('BUY_GOLD', 'after-retry')->assertJsonPath('successful_response_count', 1)->assertJsonPath('cooldown_until', '2026-01-31T12:00:00Z');
        $this->assertDatabaseCount('feedback_answers', 1);
    }

    public function test_retry_does_not_restart_cooldown_and_old_invitation_cannot_bypass_it(): void
    {
        $this->prepare();
        $context = $this->context('BUY_GOLD', 'buy-1');
        $invitation = $this->apiPost('eligibility', $context, 'inv')->assertCreated()->json('invitation_id');
        $other = $this->eligibility('BUY_GOLD', 'buy-2')->assertCreated()->json('invitation_id');
        $payload = $this->response($invitation);
        $first = $this->apiPost('responses', $payload, 'submit')->assertCreated()->json();
        $this->travel(2)->hours();
        $this->apiPost('responses', $payload, 'submit')->assertOk()->assertExactJson($first);
        $this->apiPost('responses', $payload, 'new-key-same-submission')->assertOk()->assertExactJson($first);
        $this->apiPost('responses', $this->response($other, 'another-answer'), 'another-answer')->assertUnprocessable();
        $this->apiPost('eligibility', $context, 'inv')->assertOk()->assertJsonPath('eligible', false)->assertJsonPath('reason', 'cooldown_active')
            ->assertJsonPath('successful_response_count', 1)->assertJsonPath('cooldown_until', '2026-01-31T12:00:00Z');
        $this->assertDatabaseCount('feedback_responses', 1);
    }

    public function test_30_day_boundary_allows_next_response_but_never_resets_three_response_limit(): void
    {
        $this->prepare();
        $invitation = $this->eligibility('BUY_GOLD', 'first')->assertCreated()->json('invitation_id');
        $this->apiPost('responses', $this->response($invitation), 'first')->assertCreated();
        $this->travelTo(Carbon::parse('2026-01-31T11:59:59Z'));
        $denied = $this->context('BUY_GOLD', 'too-soon');
        $this->apiPost('eligibility', $denied, 'too-soon')->assertJsonPath('reason', 'cooldown_active');
        $this->travel(1)->second();
        $this->apiPost('eligibility', $denied, 'too-soon')->assertJsonPath('eligible', false)->assertJsonPath('reason', 'evaluation_retry_requires_new_key')->assertJsonPath('successful_response_count', 1);
        $next = $this->eligibility('BUY_GOLD', 'second')->assertCreated()->assertJsonPath('successful_response_count', 1)->json('invitation_id');
        $this->apiPost('responses', $this->response($next, 'second'), 'second')->assertCreated();
        $this->travel(30)->days();
        $third = $this->eligibility('BUY_GOLD', 'third')->assertCreated()->assertJsonPath('successful_response_count', 2)->json('invitation_id');
        $this->apiPost('responses', $this->response($third, 'third'), 'third')->assertCreated();
        $this->travel(30)->days();
        $this->eligibility('BUY_GOLD', 'fourth')->assertOk()->assertJsonPath('eligible', false)->assertJsonPath('reason', 'response_limit_reached')
            ->assertJsonPath('successful_response_count', 3)->assertJsonPath('remaining_responses', 0)->assertJsonPath('reset_policy', 'pending_confirmation');
        $this->travel(365)->days();
        $newRules = app(Changes::class)->ruleDraft($this->staff, $this->rules());
        foreach (['submit', 'approve', 'publish'] as $action) {
            app(Changes::class)->transition($this->staff, 'rules', $newRules->id, 1, $action);
        }
        $this->eligibility('BUY_GOLD', 'year-later')->assertJsonPath('reason', 'response_limit_reached')->assertJsonPath('successful_response_count', 3);
        $this->eligibility('SALE_GOLD', 'sale')->assertCreated()->assertJsonPath('successful_response_count', 0);
        $this->assertDatabaseCount('feedback_responses', 3);
    }

    public function test_always_on_and_failed_success_verification_do_not_affect_popup_state(): void
    {
        $this->prepare();
        $context = $this->context('BUY_GOLD', 'unverified');
        $context['transaction_context']['success_verified'] = false;
        $this->apiPost('eligibility', $context, 'unverified')->assertUnprocessable();
        $this->assertDatabaseCount('feedback_invitations', 0);
        $payload = $this->response('unused');
        unset($payload['invitation_id']);
        $payload['source'] = 'always_on';
        for ($i = 0; $i < 5; $i++) {
            $payload['client_submission_id'] = 'always-'.$i;
            $this->apiPost('responses', $payload, 'always-'.$i)->assertCreated();
        }
        $invitation = $this->eligibility('BUY_GOLD', 'buy')->assertCreated()->assertJsonPath('successful_response_count', 0)->assertJsonPath('cooldown_until', null)->json('invitation_id');
        $this->apiPost('responses', $this->response($invitation, 'popup'), 'popup')->assertCreated();
        $this->assertDatabaseCount('feedback_responses', 6);
    }

    public function test_legacy_published_rules_use_effective_policy_without_rewriting_history(): void
    {
        $this->prepare();
        $legacy = ['channels' => ['auto_prompt' => true, 'always_on' => true], 'transaction_types' => ['BUY_GOLD', 'SALE_GOLD'], 'cooldown_seconds' => 5, 'rate_limit' => 5, 'rate_window_seconds' => 3600, 'invitation_ttl_seconds' => 86400];
        $version = RuleVersion::create(['version' => 2, 'status' => 'published', 'rules' => $legacy, 'published_at' => now()]);
        $id = $this->eligibility('BUY_GOLD', 'buy')->assertCreated()->json('invitation_id');
        $this->apiPost('responses', $this->response($id), 'submit')->assertCreated();
        $this->travel(6)->seconds();
        $this->eligibility('BUY_GOLD', 'later')->assertJsonPath('reason', 'cooldown_active');
        $this->getJson('/internal/v1/feedback/config?survey_code=popup-test')->assertOk()->assertJsonPath('rules.transaction_rules.0.max_responses', 3)->assertJsonPath('rules.transaction_rules.0.cooldown_days', 30);
        $this->assertSame(Idempotency::hash($legacy), Idempotency::hash($version->fresh()->rules));
    }

    public function test_withdraw_gold_can_be_enabled_and_published_without_enabling_other_transactions(): void
    {
        $this->prepare();
        $this->actingAs($this->staff);
        $version = RuleVersion::firstOrFail();
        Livewire::test(ListRules::class)->callTableAction('newVersion', $version, data: ['enabled_transaction_types' => ['WITHDRAW_GOLD']])
            ->assertHasNoTableActionErrors();
        $draft = RuleVersion::where('status', 'draft')->firstOrFail();
        $rules = collect($draft->rules['transaction_rules'])->keyBy('transaction_type');
        $this->assertTrue($rules['WITHDRAW_GOLD']['enabled']);
        $this->assertFalse($rules['BUY_GOLD']['enabled']);
        $this->assertFalse($rules['SALE_GOLD']['enabled']);
        foreach (['submit', 'approve', 'publish'] as $action) {
            app(Changes::class)->transition($this->staff, 'rules', $draft->id, 1, $action);
        }
        $id = $this->eligibility('WITHDRAW_GOLD', 'withdraw')->assertCreated()->assertJsonPath('eligible', true)->json('invitation_id');
        $this->apiPost('responses', $this->response($id), 'withdraw-answer')->assertCreated();
        $this->eligibility('WITHDRAW_GOLD', 'withdraw-next')->assertJsonPath('reason', 'cooldown_active')->assertJsonPath('successful_response_count', 1);
        $this->eligibility('BUY_GOLD', 'buy-disabled')->assertJsonPath('reason', 'trigger_disabled');
    }

    public function test_filament_rules_show_pending_policy_and_save_independent_type_switches(): void
    {
        $this->prepare();
        $this->actingAs($this->staff);
        $version = RuleVersion::firstOrFail();
        $rules = $this->rules();
        $rules['transaction_rules'][1]['enabled'] = false;
        Livewire::test(ListRules::class)->callTableAction('newVersion', $version, data: ['enabled_transaction_types' => ['BUY_GOLD']])
            ->assertHasNoTableActionErrors();
        $draft = RuleVersion::where('status', 'draft')->firstOrFail();
        $this->assertTrue($version->fresh()->rules['transaction_rules'][1]['enabled']);
        foreach (['submit', 'approve', 'publish'] as $action) {
            app(Changes::class)->transition($this->staff, 'rules', $draft->id, 1, $action);
        }
        $this->eligibility('BUY_GOLD', 'buy')->assertCreated();
        $this->eligibility('SALE_GOLD', 'sale')->assertOk()->assertJsonPath('reason', 'trigger_disabled');
        foreach (['en', 'lo'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(ListRules::class)->mountTableAction('details', $draft->fresh())->assertMountedActionModalSee([__('feedback.rules_detail_note'), __('feedback.rules_detail_limit'), __('feedback.rules_detail_cooldown')]);
        }
        $rules['transaction_rules'][0]['max_responses'] = 4;
        $this->expectException(ValidationException::class);
        app(Changes::class)->ruleDraft($this->staff, $rules);
    }
}
