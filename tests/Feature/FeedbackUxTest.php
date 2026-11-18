<?php

namespace Tests\Feature;

use App\Application\Analytics;
use App\Application\Changes;
use App\Application\ChannelPolicy;
use App\Application\ExportRenderer;
use App\Application\Exports;
use App\Application\OtherQuestions;
use App\Application\PopupPolicy;
use App\Application\ReportFilters;
use App\Filament\Resources\Pages\ListCustomerServices;
use App\Filament\Resources\Pages\ListOtherQuestions;
use App\Filament\Resources\Pages\ListRules;
use App\Filament\Support\SurveyFields;
use App\Jobs\BuildFeedbackExport;
use App\Models\RuleVersion;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FeedbackUxTest extends TestCase
{
    use RefreshDatabase;

    private function setupSurvey(): array
    {
        $this->freezeTime();
        config(['feedback.staff_auth' => 'local', 'feedback.services.app-backend.token_hash' => hash('sha256', 'ux-token')]);
        $staff = User::factory()->create(['active' => true, 'permissions' => config('feedback.permissions')]);
        $version = app(Changes::class)->surveyDraft($staff, ['code' => 'ux', 'name' => 'UX survey', 'questions' => [
            ['code' => 'rating', 'type' => 'rating', 'label' => ['en' => 'Rating', 'lo' => 'ຄະແນນ'], 'required' => true, 'max_length' => 500, 'max_choices' => 1, 'options' => []],
            ['code' => 'comment', 'type' => 'text', 'label' => ['en' => 'Comment', 'lo' => 'ຄຳຄິດເຫັນ'], 'required' => false, 'max_length' => 500, 'max_choices' => 1, 'options' => []],
        ]]);
        $rule = app(Changes::class)->ruleDraft($staff, ['channels' => ['auto_prompt' => true, 'always_on' => true], 'transaction_rules' => PopupPolicy::normalizeRules(['transaction_types' => ['BUY_GOLD', 'SALE_GOLD']])['transaction_rules'], 'rate_limit' => 20, 'rate_window_seconds' => 60, 'invitation_ttl_seconds' => 86400]);
        $this->publish($staff, 'survey', $version);
        $this->publish($staff, 'rules', $rule);
        $this->withHeaders(['Authorization' => 'Bearer ux-token', 'X-Service-ID' => 'app-backend']);

        return [$staff, $version->fresh('questions.options'), $rule->fresh()];
    }

    private function publish(User $staff, string $type, $version): void
    {
        foreach (['submit', 'approve', 'publish'] as $action) {
            app(Changes::class)->transition($staff, $type, $version->id, $version->revision, $action);
        }
    }

    private function payload(SurveyVersion $version, string $id = 'one', int $rating = 5): array
    {
        return ['actor_user_id' => 'ux-actor', 'client_submission_id' => $id, 'survey_version_id' => $version->id, 'source' => 'always_on', 'submitted_at' => now()->format('Y-m-d\TH:i:s\Z'), 'answers' => [['question_id' => $version->questions->firstWhere('type', 'rating')->id, 'rating_value' => $rating]]];
    }

    private function send(array $payload, string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/internal/v1/feedback/responses', $payload);
    }

    private function eligibility(SurveyVersion $version, string $key, string $actor = 'ux-actor')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/internal/v1/feedback/eligibility', [
            'actor_user_id' => $actor, 'survey_version_id' => $version->id,
            'transaction_context' => ['transaction_id' => $key, 'transaction_type' => 'BUY_GOLD', 'transaction_occurred_at' => now()->subSeconds(2)->format('Y-m-d\TH:i:s\Z'), 'verified_at' => now()->subSecond()->format('Y-m-d\TH:i:s\Z'), 'success_verified' => true],
        ]);
    }

    public function test_published_content_channels_and_disabled_questions_preserve_history(): void
    {
        [$staff, $old] = $this->setupSurvey();
        $data = SurveyFields::data($old);
        $data['channels'] = ['always_on'];
        $data['content']['thank_you'] = ['en' => 'Thank you!', 'lo' => 'ຂອບໃຈ'];
        $data['questions'][1]['enabled'] = false;
        $draft = app(Changes::class)->surveyDraft($staff, $data);
        $this->getJson('/internal/v1/feedback/config?survey_code=ux')->assertJsonPath('survey.id', $old->id);
        $this->publish($staff, 'survey', $draft);
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=always_on')->assertOk()->assertJsonCount(1, 'app_config.questions')->assertJsonPath('app_config.content.thank_you.lo', 'ຂອບໃຈ')->assertJsonPath('app_config.channels.auto_prompt', false)->assertJsonMissingPath('app_config.rate_limit');
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=auto_prompt')->assertNotFound();
        $this->eligibility($draft, 'disabled-survey')->assertUnprocessable();
        $draft->load('questions.options');
        $payload = $this->payload($draft);
        $payload['answers'][] = ['question_id' => $draft->questions->firstWhere('type', 'text')->id, 'text_value' => 'Disabled question'];
        $this->send($payload, 'disabled-question')->assertUnprocessable();
        $this->assertDatabaseCount('feedback_responses', 0);
        $this->assertTrue($old->fresh('questions')->questions->firstWhere('type', 'text')->enabled);
        $this->send($this->payload($old), 'old-version')->assertCreated();
    }

    public function test_schedule_boundaries_and_closing_an_open_form_preserve_successful_retries(): void
    {
        [$staff, $version, $rule] = $this->setupSurvey();
        $start = now()->addHour();
        $end = $start->copy()->addHour();
        $rules = array_replace($rule->rules, ['popup_schedule' => ['starts_at' => $start->format('Y-m-d\TH:i:s\Z'), 'ends_at' => $end->format('Y-m-d\TH:i:s\Z')]]);
        $scheduled = app(Changes::class)->ruleDraft($staff, $rules);
        $this->publish($staff, 'rules', $scheduled);
        $this->eligibility($version, 'before')->assertOk()->assertJsonPath('reason', 'outside_schedule');
        $this->travelTo($start);
        $invitation = $this->eligibility($version, 'at-start')->assertCreated()->json('invitation_id');
        $data = $this->payload($version);
        $data['source'] = 'auto_prompt';
        $data['invitation_id'] = $invitation;
        $receipt = $this->send($data, 'at-start-response')->assertCreated()->json();
        $pending = $this->eligibility($version, 'open-form', 'another')->assertCreated()->json('invitation_id');
        $this->travelTo($end);
        $this->eligibility($version, 'at-end', 'another')->assertOk()->assertJsonPath('reason', 'outside_schedule');
        $data2 = $this->payload($version, 'late');
        $data2['actor_user_id'] = 'another';
        $data2['source'] = 'auto_prompt';
        $data2['invitation_id'] = $pending;
        $this->send($data2, 'late-response')->assertUnprocessable();
        $this->send($data, 'at-start-response')->assertOk()->assertExactJson($receipt);
        $this->send($this->payload($version, 'always-on'), 'always-on')->assertCreated();
        $this->assertDatabaseCount('feedback_responses', 2);
    }

    public function test_customer_service_draft_is_independent_from_popup_and_does_not_delete_answers(): void
    {
        [$staff, $version, $rule] = $this->setupSurvey();
        $payload = $this->payload($version);
        $receipt = $this->send($payload, 'saved')->assertCreated()->json();
        $this->actingAs($staff);
        $customerService = ChannelPolicy::customerService([]);
        $customerService['enabled'] = false;
        Livewire::test(ListCustomerServices::class)->callTableAction('newVersion', $rule, data: ['revision' => 1, 'customer_service' => $customerService])->assertHasNoActionErrors();
        $draft = RuleVersion::where('status', 'draft')->firstOrFail();
        $this->assertSame($rule->rules['transaction_rules'], $draft->rules['transaction_rules']);
        $this->send($this->payload($version, 'before-close'), 'before-close')->assertCreated();
        $this->publish($staff, 'rules', $draft);
        $this->getJson('/internal/v1/feedback/config?survey_code=ux')->assertJsonPath('app_config.channels.always_on', false)->assertJsonPath('app_config.channels.auto_prompt', true);
        $this->send($this->payload($version, 'new-after-close'), 'after-close')->assertUnprocessable();
        $this->send($payload, 'saved')->assertOk()->assertExactJson($receipt);
        $this->eligibility($version, 'popup-still-open')->assertCreated();
        $this->assertDatabaseCount('feedback_responses', 2);
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['feedback.read']]);
        $this->actingAs($viewer)->get('/admin/customer-service')->assertForbidden();
    }

    public function test_always_on_daily_mockup_is_not_enforced_and_rule_form_roundtrips_schedule(): void
    {
        [$staff, $version, $rule] = $this->setupSurvey();
        foreach (range(1, 4) as $n) {
            $this->send($this->payload($version, 'daily-'.$n), 'daily-'.$n)->assertCreated();
        }
        $this->assertFalse($rule->rules['always_on_frequency']['enforced']);
        $this->actingAs($staff);
        Livewire::test(ListRules::class)->callTableAction('newVersion', $rule, data: array_replace($rule->rules, ['popup_schedule' => ['starts_at' => '2026-12-01 07:00:00', 'ends_at' => '2026-12-02 07:00:00']]))->assertHasNoActionErrors();
        $draft = RuleVersion::where('status', 'draft')->firstOrFail();
        $this->assertSame('2026-12-01T00:00:00Z', $draft->rules['popup_schedule']['starts_at']);
        $this->assertFalse($draft->rules['always_on_frequency']['enforced']);
        $this->assertDatabaseCount('feedback_responses', 4);
    }

    public function test_other_question_editor_keeps_published_records_and_permissions(): void
    {
        [$staff, $version] = $this->setupSurvey();
        $this->actingAs($staff);
        $data = SurveyFields::data($version);
        $data['questions'][1]['label']['en'] = 'Updated question';
        $data['questions'][1]['enabled'] = false;
        Livewire::test(ListOtherQuestions::class)->callTableAction('editQuestion', $version->questions->firstWhere('type', 'text'), data: ['revision' => $version->revision, 'question' => $data['questions'][1]])->assertHasNoActionErrors();
        $this->assertSame('Comment', $version->fresh('questions')->questions->firstWhere('type', 'text')->label['en']);
        $this->assertSame($staff->id, $version->fresh('lastEdit.staff')->lastEdit->staff->id);
        $draft = SurveyVersion::where('status', 'draft')->firstOrFail();
        $this->assertFalse($draft->questions->firstWhere('type', 'text')->enabled);
        foreach (['en', 'lo'] as $locale) {
            $this->withSession(['feedback_locale' => $locale])->get('/admin/other-questions')->assertOk();
            $this->get('/admin/customer-service')->assertOk();
        }
    }

    public function test_excel_pdf_exports_freeze_membership_match_filters_and_escape_user_content(): void
    {
        [$staff, $version] = $this->setupSurvey();
        Queue::fake([BuildFeedbackExport::class]);
        Storage::fake('local');
        $payload = $this->payload($version, 'four', 4);
        $payload['answers'][] = ['question_id' => $version->questions->firstWhere('type', 'text')->id, 'text_value' => '=SUM(1,2) <img src="https://example.test/private"> ຂອບໃຈ'];
        $this->send($payload, 'four')->assertCreated();
        $this->send($this->payload($version, 'three', 3), 'three')->assertCreated();
        $filters = ['survey_version_id' => $version->id, 'rating' => 4, 'source' => 'always_on', 'actor_user_id' => 'ux-actor'];
        $this->assertSame(1, ReportFilters::query($filters)->count());
        $this->assertSame(0, ReportFilters::query($filters + ['transaction_type' => 'BUY_GOLD'])->count());
        app()->setLocale('lo');
        $xlsx = app(Exports::class)->create($staff, $filters, 'xlsx');
        $pdf = app(Exports::class)->create($staff, $filters + ['question_id' => $version->questions->firstWhere('type', 'rating')->id], 'pdf');
        $this->send($this->payload($version, 'late', 4), 'late')->assertCreated();
        (new BuildFeedbackExport($xlsx->id))->handle();
        (new BuildFeedbackExport($pdf->id))->handle();
        Queue::assertPushed(BuildFeedbackExport::class, 2);
        $book = IOFactory::load(Storage::disk('local')->path($xlsx->fresh()->file_ref));
        $sheet = $book->getSheetByName('Responses');
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame('s', $sheet->getCell('J3')->getDataType());
        $this->assertSame($payload['answers'][1]['text_value'], $sheet->getCell('J3')->getValue());
        $book->disconnectWorksheets();
        $this->assertSame(100, $pdf->fresh()->summary['csat']);
        $this->assertSame(1, $pdf->fresh()->summary['rating_count']);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($pdf->fresh()->file_ref));
        $html = view('exports.report', ['job' => $xlsx->fresh(), 'intro' => false, 'rows' => iterator_to_array(app(ExportRenderer::class)->rows($xlsx))])->render();
        $this->assertStringNotContainsString('<img src=', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->actingAs($staff)->get(route('exports.download', $pdf))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $other = User::factory()->create(['active' => true, 'permissions' => config('feedback.permissions')]);
        $this->actingAs($other)->get(route('exports.download', $xlsx))->assertForbidden();
        $staff->update(['permissions' => ['feedback.read', 'report.export']]);
        $this->actingAs($staff->fresh())->get(route('exports.download', $pdf))->assertForbidden();
    }

    public function test_rating_reason_limit_uses_published_config_instead_of_mockup_three(): void
    {
        [$staff, $old] = $this->setupSurvey();
        $data = SurveyFields::data($old);
        $q = &$data['questions'][0];
        $q['rating_reasons_enabled'] = true;
        $q['rating_reasons_required'] = false;
        $q['max_choices'] = 4;
        $q['rating_reasons'] = array_fill(1, 5, []);
        foreach (range(1, 5) as $n) {
            $q['rating_reasons'][5][] = ['code' => 'reason-'.$n, 'label' => ['en' => 'Reason '.$n, 'lo' => 'Reason '.$n]];
        }
        $version = app(Changes::class)->surveyDraft($staff, $data);
        $this->publish($staff, 'survey', $version);
        $version->load('questions.options');
        $payload = $this->payload($version);
        $options = $version->questions->first()->options->pluck('id');
        $payload['answers'][0]['option_ids'] = $options->take(4)->all();
        $this->send($payload, 'four-reasons')->assertCreated();
        $payload['client_submission_id'] = 'too-many';
        $payload['answers'][0]['option_ids'] = $options->all();
        $this->send($payload, 'five-reasons')->assertUnprocessable();
        $this->getJson('/internal/v1/feedback/config?survey_code=ux')->assertJsonPath('app_config.questions.0.max_choices', 4)->assertJsonCount(0, 'app_config.questions.0.rating_reasons.1');
        $this->assertDatabaseCount('answer_options', 4);
    }

    public function test_unconfirmed_frequency_unsafe_icon_and_invalid_schedule_are_rejected(): void
    {
        [$staff, , $rule] = $this->setupSurvey();
        $cases = [
            ['always_on_frequency' => array_replace(ChannelPolicy::frequencyProposal(), ['enforced' => true])],
            ['customer_service' => array_replace(ChannelPolicy::customerService([]), ['icon' => 'https://example.test/icon.svg'])],
            ['popup_schedule' => ['starts_at' => '2026-12-02T00:00:00Z', 'ends_at' => '2026-12-01T00:00:00Z']],
        ];
        foreach ($cases as $changes) {
            try {
                app(Changes::class)->ruleDraft($staff, array_replace($rule->rules, $changes));
                $this->fail('Invalid configuration was accepted.');
            } catch (ValidationException|HttpException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertDatabaseCount('rule_versions', 1);
    }

    public function test_exact_ux_example_has_436_average_and_85_csat(): void
    {
        [$staff, $version] = $this->setupSurvey();
        $question = $version->questions->firstWhere('type', 'rating');
        $responses = [];
        $answers = [];
        foreach ([5 => 900, 4 => 375, 3 => 125, 2 => 60, 1 => 40] as $rating => $count) {
            foreach (range(1, $count) as $n) {
                $id = (string) Str::uuid();
                $responses[] = ['id' => $id, 'caller' => 'app-backend', 'actor_user_id' => 'sample-'.$n, 'client_submission_id' => $id, 'payload_hash' => str_repeat('a', 64), 'survey_version_id' => $version->id, 'source' => 'always_on', 'submitted_at' => now(), 'received_at' => now()];
                $answers[] = ['id' => (string) Str::uuid(), 'feedback_response_id' => $id, 'question_id' => $question->id, 'rating_value' => $rating];
            }
        }
        foreach (array_chunk($responses, 300) as $chunk) {
            DB::table('feedback_responses')->insert($chunk);
        }
        foreach (array_chunk($answers, 300) as $chunk) {
            DB::table('feedback_answers')->insert($chunk);
        }
        $metrics = app(Analytics::class)->summary($staff, ['question_id' => $question->id]);
        $this->assertSame(4.36, $metrics['average']);
        $this->assertSame(85.0, $metrics['csat']);
        $this->assertSame(1500, $metrics['response_count']);
        $this->assertSame(900, $metrics['unique_customers']);
        $this->assertSame(125, $metrics['distribution'][3]);
    }

    public function test_popup_excludes_other_questions_in_config_and_submission_but_feedback_requires_them(): void
    {
        [$staff, $old] = $this->setupSurvey();
        $data = SurveyFields::data($old);
        $data['questions'][1]['required'] = true;
        $version = app(Changes::class)->surveyDraft($staff, $data);
        $this->publish($staff, 'survey', $version);
        $version->load('questions.options');
        $comment = $version->questions->firstWhere('type', 'text');
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=auto_prompt')->assertOk()->assertJsonCount(1, 'survey.questions')->assertJsonCount(1, 'app_config.questions')->assertJsonPath('app_config.questions.0.type', 'rating');
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=always_on')->assertOk()->assertJsonCount(2, 'app_config.questions')->assertJsonPath('app_config.questions.1.channels', ['always_on']);
        $payload = $this->payload($version);
        $this->send($payload, 'missing-other')->assertUnprocessable();
        $invitation = $this->eligibility($version, 'rating-only')->assertCreated()->json('invitation_id');
        $payload['source'] = 'auto_prompt';
        $payload['invitation_id'] = $invitation;
        $invalid = $payload;
        $invalid['answers'][] = ['question_id' => $comment->id, 'text_value' => 'Not allowed in popup'];
        $this->send($invalid, 'extra-other')->assertUnprocessable();
        $this->assertDatabaseCount('feedback_responses', 0);
        $receipt = $this->send($payload, 'rating-only')->assertCreated()->json();
        $this->send($payload, 'rating-only')->assertOk()->assertExactJson($receipt);
        $alwaysOn = $this->payload($version, 'feedback');
        $alwaysOn['answers'][] = ['question_id' => $comment->id, 'text_value' => 'Feedback page comment'];
        $this->send($alwaysOn, 'feedback')->assertCreated();
        $this->assertDatabaseCount('feedback_responses', 2);
        $this->assertDatabaseCount('feedback_answers', 3);
    }

    public function test_other_question_enable_disable_is_versioned_and_preserves_rating_and_answers(): void
    {
        [$staff, $version] = $this->setupSurvey();
        $comment = $version->questions->firstWhere('type', 'text');
        $payload = $this->payload($version);
        $payload['answers'][] = ['question_id' => $comment->id, 'text_value' => 'Keep this answer'];
        $this->send($payload, 'before-disable')->assertCreated();
        $this->actingAs($staff);
        Livewire::test(ListOtherQuestions::class)->assertCanSeeTableRecords([$comment])->assertCanNotSeeTableRecords([$version->questions->firstWhere('type', 'rating')])->callTableAction('toggleEnabled', $comment, data: ['revision' => 1])->assertHasNoActionErrors();
        $draft = SurveyVersion::where('status', 'draft')->firstOrFail();
        $this->assertFalse($draft->questions->firstWhere('code', 'comment')->enabled);
        $this->assertTrue($comment->fresh()->enabled);
        $this->assertSame('Rating', $draft->questions->firstWhere('type', 'rating')->label['en']);
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=always_on')->assertJsonCount(2, 'app_config.questions');
        $this->publish($staff, 'survey', $draft);
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=always_on')->assertJsonCount(1, 'app_config.questions');
        $disabled = $draft->questions->firstWhere('code', 'comment');
        Livewire::test(ListOtherQuestions::class)->callTableAction('toggleEnabled', $disabled, data: ['revision' => 1])->assertHasNoActionErrors();
        $enabled = SurveyVersion::where('status', 'draft')->firstOrFail();
        $this->publish($staff, 'survey', $enabled);
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=always_on')->assertJsonCount(2, 'app_config.questions');
        $this->assertDatabaseHas('feedback_answers', ['question_id' => $comment->id, 'text_value' => 'Keep this answer']);
    }

    public function test_other_question_create_reorder_remove_and_stale_revision_are_safe(): void
    {
        [$staff, $version] = $this->setupSurvey();
        $this->actingAs($staff);
        $question = ['code' => 'extra', 'type' => 'choice', 'label' => ['en' => 'Extra question', 'lo' => 'Extra question'], 'enabled' => true, 'required' => false, 'max_length' => 500, 'max_choices' => 2, 'options' => [
            ['code' => 'b', 'label' => ['en' => 'Second', 'lo' => 'Second']],
            ['code' => 'a', 'label' => ['en' => 'First', 'lo' => 'First']],
        ]];
        Livewire::test(ListOtherQuestions::class)->callTableAction('createQuestion', data: ['survey_version_id' => $version->id, 'revision' => 1, 'question' => $question])->assertHasNoActionErrors();
        $draft = SurveyVersion::where('status', 'draft')->firstOrFail();
        $extra = $draft->questions->firstWhere('code', 'extra');
        $this->assertSame(['b', 'a'], $extra->options->pluck('code')->all());
        $this->assertCount(3, $draft->questions);
        $this->assertCount(2, $version->fresh('questions')->questions);
        Livewire::test(ListOtherQuestions::class)->callTableAction('removeQuestion', $extra, data: ['revision' => 1])->assertHasNoActionErrors();
        $this->assertCount(2, $draft->fresh('questions')->questions);
        try {
            app(OtherQuestions::class)->save($staff, $draft->id, 1, null, $question);
            $this->fail('Stale revision accepted');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['survey.read']]);
        $this->actingAs($viewer);
        Livewire::test(ListOtherQuestions::class)->assertTableActionHidden('createQuestion');
        try {
            app(OtherQuestions::class)->save($viewer, $version->id, 1, null, $question);
            $this->fail('Read-only user edited questions');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This action is unauthorized.', $exception->getMessage());
        }
        $this->assertDatabaseCount('survey_versions', 2);
    }

    public function test_popup_is_unavailable_when_no_enabled_rating_question_exists(): void
    {
        [$staff, $old] = $this->setupSurvey();
        $data = SurveyFields::data($old);
        $data['questions'][0]['enabled'] = false;
        $version = app(Changes::class)->surveyDraft($staff, $data);
        $this->publish($staff, 'survey', $version);
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=auto_prompt')->assertJsonCount(0, 'app_config.questions')->assertJsonPath('app_config.channels.auto_prompt', false);
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=always_on')->assertJsonCount(1, 'app_config.questions');
        $this->eligibility($version, 'no-rating')->assertUnprocessable();
        $this->assertDatabaseCount('feedback_invitations', 0);
    }

    public function test_popup_stores_optional_comment_with_rating_reasons_atomically_and_retries_once(): void
    {
        [$staff, $old] = $this->setupSurvey();
        $data = SurveyFields::data($old);
        $data['questions'][0]['rating_reasons_enabled'] = true;
        $data['questions'][0]['rating_reasons_required'] = true;
        foreach (range(1, 5) as $score) {
            $data['questions'][0]['rating_reasons'][$score] = [['code' => 'service', 'label' => ['en' => 'Service', 'lo' => 'Service']]];
        }
        $version = app(Changes::class)->surveyDraft($staff, $data);
        $this->publish($staff, 'survey', $version);
        $version->load('questions.options');
        $this->getJson('/internal/v1/feedback/config?survey_code=ux&source=auto_prompt')->assertOk()
            ->assertJsonCount(1, 'app_config.questions')
            ->assertJsonPath('app_config.questions.0.comment.label.lo', 'ຄຳແນະນຳເພີ່ມເຕີມ')
            ->assertJsonPath('app_config.questions.0.comment.required', false)
            ->assertJsonPath('app_config.questions.0.comment.max_length', 500)
            ->assertJsonPath('app_config.questions.0.comment.channels', ['auto_prompt'])
            ->assertJsonPath('app_config.source', 'auto_prompt')
            ->assertJsonPath('app_config.survey_code', 'ux')
            ->assertJsonPath('app_config.survey_version', 2)
            ->assertJsonPath('app_config.published_at', now()->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('app_config.rating_levels', [5, 4, 3, 2, 1]);
        $this->getJson('/internal/v1/feedback/config?survey_code=ux')->assertOk()->assertJsonPath('app_config.source', 'always_on')->assertJsonCount(2, 'app_config.questions');
        $payload = $this->payload($version);
        $payload['source'] = 'auto_prompt';
        $payload['invitation_id'] = $this->eligibility($version, 'comment')->assertCreated()->json('invitation_id');
        $payload['answers'][0]['option_ids'] = [$version->questions->first()->options->firstWhere('rating_value', 5)->id];
        $payload['answers'][0]['text_value'] = 'ຂອບໃຈ ບໍລິການດີ';
        $receipt = $this->send($payload, 'comment')->assertCreated()->json();
        $this->send($payload, 'comment')->assertOk()->assertExactJson($receipt);
        $payload['answers'][0]['text_value'] = 'Changed comment';
        $this->send($payload, 'comment')->assertConflict();
        $this->assertDatabaseCount('feedback_responses', 1);
        $this->assertDatabaseCount('feedback_answers', 1);
        $this->assertDatabaseCount('answer_options', 1);
        $this->assertDatabaseHas('feedback_answers', ['feedback_response_id' => $receipt['response_id'], 'rating_value' => 5, 'text_value' => 'ຂອບໃຈ ບໍລິການດີ']);
        $this->eligibility($version, 'comment-cooldown')->assertJsonPath('reason', 'cooldown_active');
    }

    public function test_popup_comment_length_type_and_optional_empty_values(): void
    {
        [, $version] = $this->setupSurvey();
        $payload = $this->payload($version);
        $payload['source'] = 'auto_prompt';
        $payload['invitation_id'] = $this->eligibility($version, 'limits')->assertCreated()->json('invitation_id');
        foreach ([str_repeat('ກ', 501), ['not a string']] as $index => $comment) {
            $payload['answers'][0]['text_value'] = $comment;
            $this->send($payload, 'invalid-comment-'.$index)->assertUnprocessable();
        }
        $this->assertDatabaseCount('feedback_responses', 0);
        $payload['answers'][0]['text_value'] = str_repeat('ກ', 500);
        $this->send($payload, 'boundary-comment')->assertCreated();
        foreach (['omitted', 'empty', 'null'] as $case) {
            $optional = $this->payload($version, $case);
            $optional['actor_user_id'] = $case;
            $optional['source'] = 'auto_prompt';
            $optional['invitation_id'] = $this->eligibility($version, $case, $case)->assertCreated()->json('invitation_id');
            if ($case !== 'omitted') {
                $optional['answers'][0]['text_value'] = $case === 'empty' ? '' : null;
            }
            $this->send($optional, $case)->assertCreated();
        }
        $alwaysOn = $this->payload($version, 'wrong-channel');
        $alwaysOn['answers'][0]['text_value'] = 'Use a separate text question on Feedback';
        $this->send($alwaysOn, 'wrong-channel')->assertUnprocessable();
        $alwaysOn = $this->payload($version, 'empty-text-question');
        $alwaysOn['answers'][] = ['question_id' => $version->questions->firstWhere('type', 'text')->id, 'text_value' => null];
        $this->send($alwaysOn, 'empty-text-question')->assertUnprocessable();
        $this->assertDatabaseCount('feedback_responses', 4);
        $this->assertDatabaseCount('feedback_answers', 4);
    }
}
