<?php

namespace Tests\Feature;

use App\Application\Analytics;
use App\Application\Changes;
use App\Application\Cohorts;
use App\Application\Customers;
use App\Application\Exports;
use App\Application\Invitations;
use App\Application\PopupPolicy;
use App\Application\ReportFilters;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Pages\ListSurveys;
use App\Filament\Widgets\Metrics;
use App\Jobs\BuildFeedbackExport;
use App\Models\CohortMember;
use App\Models\FeedbackAnswer;
use App\Models\FeedbackResponse;
use App\Models\RuleVersion;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $staff;

    protected SurveyVersion $survey;

    protected RuleVersion $rule;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feedback.staff_auth' => 'local', 'feedback.services.app-backend.token_hash' => hash('sha256', 'test-service-token'), 'feedback.services.user-adapter.token_hash' => hash('sha256', 'test-cohort-token')]);
        $this->staff = User::factory()->create(['active' => true, 'permissions' => config('feedback.permissions')]);
        $this->survey = app(Changes::class)->surveyDraft($this->staff, $this->draft());
        $this->rule = app(Changes::class)->ruleDraft($this->staff, ['channels' => ['auto_prompt' => true, 'always_on' => true], 'transaction_rules' => PopupPolicy::normalizeRules(['transaction_types' => ['BUY_GOLD']])['transaction_rules'], 'rate_limit' => 5, 'rate_window_seconds' => 3600, 'invitation_ttl_seconds' => 86400]);
        foreach ([['survey', $this->survey], ['rules', $this->rule]] as [$type,$version]) {
            foreach (['submit', 'approve', 'publish'] as $action) {
                app(Changes::class)->transition($this->staff, $type, $version->id, 1, $action);
            }
        }
    }

    protected function draft(): array
    {
        return ['code' => 'experience', 'name' => 'Experience', 'questions' => [
            ['code' => 'overall', 'type' => 'rating', 'label' => ['en' => 'Overall', 'lo' => 'ຄະແນນ'], 'required' => true, 'max_length' => 100, 'max_choices' => 1, 'options' => []],
            ['code' => 'comment', 'type' => 'text', 'label' => ['en' => 'Comment', 'lo' => 'ຄຳເຫັນ'], 'required' => false, 'max_length' => 100, 'max_choices' => 1, 'options' => []],
            ['code' => 'choice', 'type' => 'choice', 'label' => ['en' => 'Improve', 'lo' => 'ປັບປຸງ'], 'required' => false, 'max_length' => 100, 'max_choices' => 2, 'options' => [['code' => 'speed', 'label' => ['en' => 'Speed', 'lo' => 'ໄວ']], ['code' => 'ease', 'label' => ['en' => 'Ease', 'lo' => 'ງ່າຍ']]]],
        ]];
    }

    protected function payload(string $submission = 'sub-1', string $actor = 'actor-1'): array
    {
        return ['client_submission_id' => $submission, 'actor_user_id' => $actor, 'survey_version_id' => $this->survey->id, 'source' => 'always_on', 'submitted_at' => now()->subSeconds(2)->utc()->format('Y-m-d\TH:i:s\Z'), 'answers' => [['question_id' => $this->survey->questions()->where('type', 'rating')->first()->id, 'rating_value' => 5]]];
    }

    protected function api(array $payload, string $key = 'test-key')
    {
        return $this->withHeaders(['Authorization' => 'Bearer test-service-token', 'X-Service-ID' => 'app-backend', 'Idempotency-Key' => $key])->postJson('/internal/v1/feedback/responses', $payload);
    }

    protected function filters(): array
    {
        return ['survey_version_id' => $this->survey->id, 'question_id' => $this->survey->questions()->where('type', 'rating')->first()->id];
    }

    public function test_commit_replay_timeout_and_payload_conflict(): void
    {
        $data = $this->payload();
        $first = $this->api($data)->assertCreated()->json();
        // Simulate a lost response after commit: replay through a new request.
        $this->api($data)->assertOk()->assertExactJson($first);
        $this->api($data, 'different-key')->assertOk()->assertExactJson($first);
        $data['answers'][0]['rating_value'] = 4;
        $this->api($data)->assertConflict();
        $this->api($data, 'third-key')->assertConflict();
        $data['answers'][0]['rating_value'] = 99;
        $this->api($data)->assertConflict();
        $this->assertDatabaseCount('feedback_responses', 1);
        $this->assertDatabaseCount('feedback_answers', 1);
        $this->assertDatabaseCount('idempotency_records', 2);
    }

    public function test_internal_auth_scopes_and_owned_reads(): void
    {
        $this->postJson('/internal/v1/feedback/responses', $this->payload())->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer test-cohort-token', 'X-Service-ID' => 'user-adapter', 'Idempotency-Key' => 'a'])->postJson('/internal/v1/feedback/responses', $this->payload())->assertForbidden();
        $id = $this->api($this->payload())->assertCreated()->json('response_id');
        $this->getJson('/internal/v1/feedback/responses/'.$id.'?actor_user_id=other')->assertNotFound();
        $this->getJson('/internal/v1/feedback/responses/'.$id.'?actor_user_id=actor-1')->assertOk();
    }

    public function test_invalid_answers_roll_back_and_allow_correction_with_same_key(): void
    {
        $data = $this->payload();
        $data['answers'][0]['rating_value'] = 6;
        $this->api($data)->assertUnprocessable();
        $data['answers'][0]['rating_value'] = 4;
        $data['answers'][0]['text_value'] = 'bad type';
        $this->api($data)->assertUnprocessable();
        $data['answers'][0] = ['question_id' => $this->survey->questions()->where('type', 'text')->first()->id, 'text_value' => 'only optional'];
        $this->api($data)->assertUnprocessable();
        $this->assertDatabaseCount('feedback_responses', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
        $this->api($this->payload())->assertCreated();
    }

    public function test_cross_version_question_choice_text_and_numeric_type_validation(): void
    {
        $foreign = app(Changes::class)->surveyDraft($this->staff, $this->draft());
        $data = $this->payload();
        $data['answers'][0]['question_id'] = $foreign->questions()->first()->id;
        $this->api($data)->assertUnprocessable();
        $data = $this->payload();
        $data['answers'][0]['rating_value'] = '5';
        $this->api($data)->assertUnprocessable();
        $data = $this->payload();
        $data['answers'][] = ['question_id' => $this->survey->questions()->where('type', 'text')->first()->id, 'text_value' => str_repeat('x', 101)];
        $this->api($data)->assertUnprocessable();
        $choice = $this->survey->questions()->where('type', 'choice')->first();
        $data = $this->payload();
        $data['answers'][] = ['question_id' => $choice->id, 'option_ids' => [$foreign->questions()->where('type', 'choice')->first()->options()->first()->id]];
        $this->api($data)->assertUnprocessable();
        $data['answers'][1]['option_ids'] = $choice->options()->pluck('id')->all();
        $this->api($data)->assertCreated();
    }

    public function test_rate_limit_does_not_block_existing_replay(): void
    {
        $original = $this->payload('s0');
        $this->api($original, 'k0')->assertCreated();
        for ($i = 1; $i < 5; $i++) {
            $this->api($this->payload('s'.$i), 'k'.$i)->assertCreated();
        }
        $this->api($this->payload('s6'), 'k6')->assertStatus(429)->assertHeader('Retry-After', '3600');
        $this->api($original, 'k0')->assertOk();
        $this->assertDatabaseCount('feedback_responses', 5);
    }

    protected function eligibilityData(): array
    {
        return ['actor_user_id' => 'actor-1', 'survey_version_id' => $this->survey->id, 'transaction_context' => ['transaction_id' => 'txn-1', 'transaction_type' => 'BUY_GOLD', 'transaction_occurred_at' => now()->subMinutes(2)->utc()->format('Y-m-d\TH:i:s\Z'), 'verified_at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'), 'success_verified' => true]];
    }

    public function test_auto_prompt_requires_verified_success_owned_unexpired_invitation(): void
    {
        $data = $this->payload();
        $data['source'] = 'auto_prompt';
        $this->api($data)->assertUnprocessable();
        $eligibility = $this->eligibilityData();
        $this->withHeaders(['Idempotency-Key' => 'inv'])->postJson('/internal/v1/feedback/eligibility', array_replace_recursive($eligibility, ['transaction_context' => ['success_verified' => false]]))->assertUnprocessable();
        $inv = $this->postJson('/internal/v1/feedback/eligibility', $eligibility)->assertCreated()->json();
        $this->postJson('/internal/v1/feedback/eligibility', $eligibility)->assertOk()->assertJson($inv);
        $data['invitation_id'] = $inv['invitation_id'];
        $data['actor_user_id'] = 'other';
        $this->api($data)->assertUnprocessable();
        $data['actor_user_id'] = 'actor-1';
        $this->api($data)->assertCreated();
        $data['client_submission_id'] = 'new';
        $this->api($data, 'new-key')->assertConflict();
    }

    public function test_late_out_of_order_events_are_deduplicated_and_shown_is_preserved(): void
    {
        $this->api($this->payload())->assertCreated();
        $inv = $this->withHeaders(['Idempotency-Key' => 'inv'])->postJson('/internal/v1/feedback/eligibility', $this->eligibilityData())->assertCreated()->json('invitation_id');
        $this->travel(10)->seconds();
        $event = ['actor_user_id' => 'actor-1', 'invitation_id' => $inv, 'event_id' => 'dismiss', 'event_type' => 'dismissed', 'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')];
        $this->withHeaders(['Idempotency-Key' => 'dismiss'])->postJson('/internal/v1/feedback/prompt-events', $event)->assertCreated();
        $event['event_id'] = 'show';
        $event['event_type'] = 'shown';
        $event['occurred_at'] = now()->subSeconds(5)->utc()->format('Y-m-d\TH:i:s\Z');
        $this->withHeaders(['Idempotency-Key' => 'show'])->postJson('/internal/v1/feedback/prompt-events', $event)->assertCreated();
        $this->withHeaders(['Idempotency-Key' => 'show-again'])->postJson('/internal/v1/feedback/prompt-events', $event)->assertOk();
        $this->assertDatabaseCount('prompt_events', 2);
    }

    public function test_snapshot_manifest_batch_dedup_and_partial_status(): void
    {
        $service = app(Cohorts::class);
        $input = ['external_id' => 'snap', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expected_count' => 2, 'expected_batches' => 2, 'identity_type' => 'account'];
        [$s] = $service->start('user-adapter', 'start', $input);
        $id = $s['snapshot_id'];
        $batch = ['batch_id' => '1', 'members' => [['actor_user_id' => 'actor-1', 'display_name' => 'Private person', 'phone' => '123456', 'identity_complete' => true]]];
        $service->members('user-adapter', 'b1', $id, $batch);
        $service->members('user-adapter', 'b1-replay', $id, $batch);
        $member = CohortMember::first();
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['customers.read']]);
        $detail = app(Customers::class)->detail($viewer, $member, []);
        $this->assertSame('incomplete', $detail['response_status']);
        $this->assertArrayNotHasKey('display_name', $detail);
        $this->assertStringNotContainsString('Private person', DB::table('cohort_members')->value('display_name'));
        try {
            $service->complete('user-adapter', 'finish', $id);
            $this->fail('Incomplete cohort accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $service->members('user-adapter', 'b2', $id, ['batch_id' => '2', 'members' => [['actor_user_id' => 'actor-2', 'identity_complete' => true]]]);
        $service->complete('user-adapter', 'finish', $id);
        $this->assertSame('unanswered', app(Customers::class)->detail($viewer, $member->fresh(), [])['response_status']);
        $this->assertSame('not_invited', app(Customers::class)->detail($viewer, $member->fresh(), [])['invitation_status']);
        $this->assertDatabaseCount('cohort_members', 2);
    }

    public function test_metric_acceptance_no_data_and_timezone_boundaries(): void
    {
        $this->assertNull(app(Analytics::class)->summary($this->staff, $this->filters())['csat']);
        $question = $this->filters()['question_id'];
        $scores = array_merge(array_fill(0, 1000, 5), array_fill(0, 275, 4), array_fill(0, 210, 2), array_fill(0, 15, 1));
        foreach ($scores as $i => $score) {
            $r = FeedbackResponse::create(['caller' => 'test', 'client_submission_id' => (string) $i, 'payload_hash' => str_repeat('a', 64), 'actor_user_id' => 'actor-'.($i % 10), 'survey_version_id' => $this->survey->id, 'source' => 'always_on', 'submitted_at' => '2026-11-11 17:00:00', 'received_at' => '2026-11-11 17:00:00']);
            FeedbackAnswer::create(['feedback_response_id' => $r->id, 'question_id' => $question, 'rating_value' => $score]);
        }
        $m = app(Analytics::class)->summary($this->staff, $this->filters() + ['from' => '2026-11-12', 'until' => '2026-11-12']);
        $this->assertSame(1500, $m['rating_count']);
        $this->assertSame(4.36, $m['average']);
        $this->assertSame(85.0, $m['csat']);
        $this->assertSame(10, $m['unique_customers']);
        $this->assertSame('2026-11-12', $m['trend'][0]['date']);
        $this->assertSame(0, ReportFilters::query(['until' => '2026-11-11'])->count());
    }

    public function test_revision_approval_and_published_immutability(): void
    {
        $version = app(Changes::class)->surveyDraft($this->staff, $this->draft());
        app(Changes::class)->surveyDraft($this->staff, $this->draft(), $version->id, 1);
        try {
            app(Changes::class)->transition($this->staff, 'survey', $version->id, 1, 'submit');
            $this->fail('Stale revision accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        foreach (['submit', 'approve', 'publish'] as $action) {
            app(Changes::class)->transition($this->staff, 'survey', $version->id, 2, $action);
        }
        $this->assertSame('published', $this->survey->fresh()->status);
        $this->expectException(\LogicException::class);
        $this->survey->fresh()->update(['revision' => 99]);
    }

    public function test_unauthorized_application_services_and_direct_pages_are_denied(): void
    {
        $viewer = User::factory()->create(['active' => true, 'permissions' => []]);
        $this->actingAs($viewer);
        foreach (['/admin', '/admin/rating-questions', '/admin/feedback', '/admin/customers', '/admin/surveys', '/admin/rules', '/admin/reports', '/admin/audits'] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach ([
            fn () => app(Analytics::class)->summary($viewer, []), fn () => app(Exports::class)->create($viewer, []),
            fn () => app(Changes::class)->surveyDraft($viewer, $this->draft()), fn () => app(Changes::class)->transition($viewer, 'survey', $this->survey->id, 1, 'approve'), fn () => app(Changes::class)->transition($viewer, 'survey', $this->survey->id, 1, 'publish'),
        ] as $action) {
            try {
                $action();
                $this->fail('Permission not enforced');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_export_private_file_formula_safety_and_revoked_download_permission(): void
    {
        Storage::fake('local');
        Queue::fake();
        $data = $this->payload();
        $data['answers'][] = ['question_id' => $this->survey->questions()->where('type', 'text')->first()->id, 'text_value' => '=HYPERLINK("bad")'];
        $this->api($data)->assertCreated();
        $job = app(Exports::class)->create($this->staff, []);
        $this->api($this->payload('later-submission', 'later-account'), 'later-key')->assertCreated();
        (new BuildFeedbackExport($job->id))->handle();
        $job->refresh();
        $this->assertSame('ready', $job->status);
        $this->assertStringNotContainsString('later-account', Storage::disk('local')->get($job->file_ref));
        $this->assertStringContainsString("'=HYPERLINK", Storage::disk('local')->get($job->file_ref));
        $this->actingAs($this->staff)->get(route('exports.download', $job))->assertOk();
        $other = User::factory()->create(['active' => true, 'permissions' => config('feedback.permissions')]);
        $this->actingAs($other)->get(route('exports.download', $job))->assertForbidden();
        $this->staff->update(['permissions' => ['feedback.read']]);
        $this->actingAs($this->staff->fresh())->get(route('exports.download', $job))->assertForbidden();
        $this->assertSame("'  =1+1", Exports::safeCell('  =1+1'));
    }

    public function test_feedback_center_only_shows_authorized_destinations(): void
    {
        $this->get('/admin/feedback-center')->assertRedirect('/admin/login');
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['feedback.read']]);
        $this->actingAs($viewer)->get('/admin/feedback-center')->assertOk()
            ->assertSee('Choose an available page from the sidebar.')
            ->assertDontSee('Manage rating questions')
            ->assertDontSee('Configure pop-up rules')
            ->assertDontSee('Manage survey questions')
            ->assertDontSee('Feedback reports &amp; exports', false);
        $this->get('/admin/rules')->assertForbidden();
    }

    public function test_staff_pages_and_livewire_render_in_both_locales(): void
    {
        $this->actingAs($this->staff);
        foreach (['en', 'lo'] as $locale) {
            $this->withSession(['feedback_locale' => $locale]);
            foreach (['/admin', '/admin/rating-questions', '/admin/feedback-center', '/admin/feedback', '/admin/customers', '/admin/surveys', '/admin/rules', '/admin/reports', '/admin/audits'] as $url) {
                $this->get($url)->assertOk();
            }
        }
        Livewire::test(Dashboard::class)->assertSuccessful();
        Livewire::test(ListSurveys::class)->assertCanSeeTableRecords([$this->survey]);
        Livewire::test(Metrics::class, ['pageFilters' => $this->filters()])->assertSee('CSAT');
    }

    public function test_filament_draft_actions_use_application_workflow(): void
    {
        $this->actingAs($this->staff);
        Livewire::test(ListSurveys::class)
            ->callTableAction('newVersion', $this->survey, data: $this->draft())
            ->assertHasNoActionErrors();
        $draft = SurveyVersion::where('status', 'draft')->firstOrFail();
        $this->assertSame(2, $draft->version);
        Livewire::test(ListSurveys::class)
            ->callTableAction('publish', $draft, data: ['revision' => 1])->assertHasNoActionErrors();
        $this->assertSame('published', $draft->fresh()->status);
    }

    public function test_choice_denominator_and_blank_rating_question_are_explicit(): void
    {
        $choice = $this->survey->questions()->where('type', 'choice')->first();
        $data = $this->payload();
        $data['answers'][] = ['question_id' => $choice->id, 'option_ids' => $choice->options()->pluck('id')->all()];
        $this->api($data)->assertCreated();
        $this->api($this->payload('second'), 'second')->assertCreated();
        $m = app(Analytics::class)->summary($this->staff, ['question_id' => $choice->id]);
        $this->assertNull($m['csat']);
        $this->assertCount(2, $m['choices']);
        foreach ($m['choices'] as $row) {
            $this->assertSame(1, $row['denominator']);
            $this->assertSame(100.0, $row['percent']);
        }
        $this->assertNull(app(Analytics::class)->summary($this->staff, [])['average']);
    }

    public function test_errors_include_request_id_and_anonymous_export_redirects(): void
    {
        $this->postJson('/internal/v1/feedback/responses', $this->payload())->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED')->assertJsonStructure(['error' => ['request_id']])->assertHeader('X-Request-ID');
        $this->get('/staff/exports/00000000-0000-0000-0000-000000000000')->assertRedirect('/admin/login');
    }

    public function test_whitespace_change_is_a_different_idempotency_payload(): void
    {
        $data = $this->payload();
        $data['answers'][] = ['question_id' => $this->survey->questions()->where('type', 'text')->first()->id, 'text_value' => 'great'];
        $this->api($data)->assertCreated();
        $data['answers'][1]['text_value'] = ' great';
        $this->api($data)->assertConflict();
    }

    public function test_expired_invitation_and_disabled_channel_are_rejected(): void
    {
        [$inv] = app(Invitations::class)->evaluate('app-backend', 'invite', $this->eligibilityData());
        $this->travel(2)->days();
        $data = $this->payload();
        $data['source'] = 'auto_prompt';
        $data['invitation_id'] = $inv['invitation_id'];
        $this->api($data)->assertUnprocessable();
        $rules = $this->rule->fresh()->rules;
        $rules['channels']['always_on'] = false;
        $new = app(Changes::class)->ruleDraft($this->staff, $rules);
        foreach (['submit', 'approve', 'publish'] as $action) {
            app(Changes::class)->transition($this->staff, 'rules', $new->id, 1, $action);
        }
        $this->api($this->payload())->assertUnprocessable();
    }
}
