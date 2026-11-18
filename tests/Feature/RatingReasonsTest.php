<?php

namespace Tests\Feature;

use App\Application\Changes;
use App\Application\Idempotency;
use App\Filament\Resources\Pages\EditRatingQuestion;
use App\Filament\Resources\Pages\ListRatingQuestions;
use App\Filament\Resources\RatingQuestionResource;
use App\Filament\Support\SurveyFields;
use App\Models\FeedbackAnswer;
use App\Models\RuleVersion;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RatingReasonsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feedback.staff_auth' => 'local', 'feedback.services.app-backend.token_hash' => hash('sha256', 'rating-test')]);
        $this->staff = User::factory()->create(['active' => true, 'permissions' => config('feedback.permissions')]);
        RuleVersion::create(['version' => 1, 'status' => 'published', 'published_at' => now(), 'rules' => ['channels' => ['always_on' => true, 'auto_prompt' => true], 'transaction_rules' => [], 'rate_limit' => 20, 'rate_window_seconds' => 60, 'invitation_ttl_seconds' => 3600]]);
        $this->withHeaders(['Authorization' => 'Bearer rating-test', 'X-Service-ID' => 'app-backend']);
    }

    private function draft(bool $required = true): array
    {
        $reasons = [];
        foreach (range(5, 1) as $rating) {
            foreach (range(1, 4) as $index) {
                $reasons[$rating][] = ['code' => 'reason-'.$index, 'label' => ['en' => "Rating $rating reason $index", 'lo' => "Rating $rating reason $index"]];
            }
        }

        return ['code' => 'rating-survey', 'name' => 'Rating survey', 'questions' => [
            ['code' => 'overall', 'type' => 'rating', 'label' => ['en' => 'Your experience?', 'lo' => 'Your experience?'], 'required' => true, 'max_length' => 2000, 'max_choices' => 3, 'options' => [], 'rating_reasons_enabled' => true, 'rating_reasons_required' => $required, 'rating_reasons' => $reasons],
            ['code' => 'comment', 'type' => 'text', 'label' => ['en' => 'Additional comments', 'lo' => 'Additional comments'], 'required' => false, 'max_length' => 500, 'max_choices' => 1, 'options' => []],
        ]];
    }

    private function publish(bool $required = true): SurveyVersion
    {
        $version = app(Changes::class)->surveyDraft($this->staff, $this->draft($required));
        foreach (['submit', 'approve', 'publish'] as $action) {
            app(Changes::class)->transition($this->staff, 'survey', $version->id, 1, $action);
        }

        return $version->fresh('questions.options');
    }

    private function payload(SurveyVersion $version, int $rating, array $options, string $id = 'answer'): array
    {
        return ['actor_user_id' => 'rating-actor', 'client_submission_id' => $id, 'survey_version_id' => $version->id, 'source' => 'always_on', 'submitted_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'answers' => [
            ['question_id' => $version->questions->firstWhere('type', 'rating')->id, 'rating_value' => $rating, 'option_ids' => $options],
            ['question_id' => $version->questions->firstWhere('type', 'text')->id, 'text_value' => 'Useful service'],
        ]];
    }

    public function test_each_rating_exposes_its_own_ordered_reasons_and_stores_them_once(): void
    {
        $version = $this->publish();
        $question = $version->questions->firstWhere('type', 'rating');
        $config = $this->getJson('/internal/v1/feedback/config?survey_code=rating-survey')->assertOk()->json('survey.questions.0');
        foreach (range(5, 1) as $rating) {
            $options = $question->options->where('rating_value', $rating)->values();
            $this->assertSame($options->pluck('id')->all(), array_column($config['rating_reasons'][$rating], 'id'));
            $payload = $this->payload($version, $rating, $options->take(3)->pluck('id')->all(), 'answer-'.$rating);
            $receipt = $this->withHeader('Idempotency-Key', 'rating-'.$rating)->postJson('/internal/v1/feedback/responses', $payload)->assertCreated()->json();
            $this->postJson('/internal/v1/feedback/responses', $payload)->assertOk()->assertExactJson($receipt);
            $answer = FeedbackAnswer::where('feedback_response_id', $receipt['response_id'])->where('question_id', $question->id)->firstOrFail();
            $this->assertSame($rating, $answer->rating_value);
            $this->assertEqualsCanonicalizing($options->take(3)->pluck('id')->all(), $answer->options->pluck('id')->all());
        }
        $this->assertDatabaseCount('feedback_responses', 5);
        $this->assertDatabaseCount('answer_options', 15);
        $this->assertDatabaseCount('feedback_answers', 10);
    }

    public function test_wrong_rating_duplicate_too_many_and_missing_required_reasons_rollback(): void
    {
        $version = $this->publish();
        $question = $version->questions->firstWhere('type', 'rating');
        $fives = $question->options->where('rating_value', 5)->pluck('id')->all();
        $cases = [[$question->options->firstWhere('rating_value', 1)->id], [$fives[0], $fives[0]], $fives, []];
        foreach ($cases as $index => $options) {
            $this->withHeader('Idempotency-Key', 'bad-'.$index)->postJson('/internal/v1/feedback/responses', $this->payload($version, 5, $options))->assertUnprocessable();
            $this->assertDatabaseMissing('idempotency_records', ['key' => 'bad-'.$index, 'operation' => 'responses.create']);
        }
        $other = $this->publish();
        $foreign = $other->questions->firstWhere('type', 'rating')->options->firstWhere('rating_value', 5)->id;
        $this->withHeader('Idempotency-Key', 'foreign')->postJson('/internal/v1/feedback/responses', $this->payload($version, 5, [$foreign]))->assertUnprocessable();
        $this->assertDatabaseCount('feedback_responses', 0);
        $this->assertDatabaseCount('feedback_answers', 0);
        $this->assertDatabaseCount('answer_options', 0);
    }

    public function test_optional_reasons_and_old_published_versions_remain_valid(): void
    {
        $version = $this->publish(false);
        $this->withHeader('Idempotency-Key', 'optional')->postJson('/internal/v1/feedback/responses', $this->payload($version, 3, []))->assertCreated();
        $before = $version->questions->first()->options->pluck('label', 'id')->all();
        $draft = SurveyFields::data($version);
        $draft['questions'][0]['rating_reasons'][5][0]['label']['en'] = 'Changed in next version';
        app(Changes::class)->surveyDraft($this->staff, $draft);
        $this->assertSame(Idempotency::hash($before), Idempotency::hash($version->fresh('questions.options')->questions->first()->options->pluck('label', 'id')->all()));
    }

    public function test_filament_drafts_clone_all_five_sets_and_preview_without_recording_feedback(): void
    {
        $version = $this->publish();
        $this->actingAs($this->staff);
        Livewire::test(ListRatingQuestions::class)->assertCanSeeTableRecords([$version]);
        $this->get(RatingQuestionResource::getUrl('edit', ['record' => $version]))->assertOk();
        Livewire::test(EditRatingQuestion::class, ['record' => $version->id])->call('save')->assertHasNoErrors();
        $draft = SurveyVersion::where('status', 'draft')->firstOrFail();
        $this->assertSame(20, $draft->questions->first()->options()->count());
        Livewire::test(ListRatingQuestions::class)->mountTableAction('preview', $draft)
            ->assertMountedActionModalSee(['Preview only', 'feedbackPreview']);
        $this->assertDatabaseCount('feedback_responses', 0);
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['feedback.read']]);
        $this->actingAs($viewer)->get('/admin/rating-questions')->assertForbidden();
    }

    public function test_draft_requires_all_five_sets_and_configures_selection_limit(): void
    {
        $data = $this->draft();
        unset($data['questions'][0]['rating_reasons'][2]);
        try {
            app(Changes::class)->surveyDraft($this->staff, $data);
            $this->fail('Missing rating set accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $data = $this->draft();
        $data['questions'][0]['max_choices'] = 4;
        $version = app(Changes::class)->surveyDraft($this->staff, $data);
        $this->assertSame(4, $version->questions->first()->max_choices);
    }

    public function test_full_page_creation_has_fixed_five_levels_and_saves_edited_options(): void
    {
        $this->actingAs($this->staff);
        $this->get(RatingQuestionResource::getUrl('create'))->assertOk();
        $page = Livewire::test(EditRatingQuestion::class);
        foreach (range(1, 5) as $score) {
            $page->assertSet('reasons.'.$score, []);
        }
        $page->call('editReasons', 5)->assertHasErrors(['data.code', 'data.name', 'data.label.en', 'data.label.lo']);
        $page->set('data.code', 'page-survey')->set('data.name', 'Page survey')
            ->set('data.label.en', 'How was your experience?')->set('data.label.lo', 'ທ່ານພໍໃຈບໍ?')
            ->call('editReasons', 5)->assertHasNoErrors()->assertSet('editingScore', 5)
            ->set('reasonData.items', [
                ['code' => 'fast', 'label' => ['en' => 'Fast service', 'lo' => 'Fast service']],
                ['code' => 'easy', 'label' => ['en' => 'Easy to use', 'lo' => 'Easy to use']],
            ])->call('applyReasons')->assertHasNoErrors()->assertSet('editingScore', null)
            ->call('editReasons', 1)->set('reasonData.items', [['code' => 'discard', 'label' => ['en' => 'Discard', 'lo' => 'Discard']]])->call('cancelReasons')
            ->assertSet('reasons.1', [])->call('save')->assertHasNoErrors();
        $draft = SurveyVersion::where('status', 'draft')->firstOrFail();
        $page->assertRedirect(RatingQuestionResource::getUrl('edit', ['record' => $draft, 'question' => 'overall']));
        $this->assertSame('How was your experience?', $draft->questions->first()->label['en']);
        $this->assertSame(['r5_fast', 'r5_easy'], $draft->questions->first()->options->pluck('code')->all());
        $this->assertDatabaseCount('feedback_responses', 0);
    }

    public function test_full_page_edit_preserves_other_questions_and_rejects_stale_save(): void
    {
        $published = $this->publish(false);
        $this->actingAs($this->staff);
        $page = Livewire::test(EditRatingQuestion::class, ['record' => $published->id]);
        $page->call('editReasons', 4)->set('reasonData.items', [
            ['code' => 'reason-2', 'label' => ['en' => 'Updated second option', 'lo' => 'Updated second option']],
            ['code' => 'reason-1', 'label' => ['en' => 'First moved down', 'lo' => 'First moved down']],
        ])->call('applyReasons')->call('save')->assertHasNoErrors();
        $draft = SurveyVersion::where('status', 'draft')->firstOrFail();
        $this->assertSame(['r4_reason-2', 'r4_reason-1'], $draft->questions->first()->options->where('rating_value', 4)->pluck('code')->values()->all());
        $this->assertCount(4, $published->fresh('questions.options')->questions->first()->options->where('rating_value', 4));
        $this->assertSame('Additional comments', $draft->questions->firstWhere('type', 'text')->label['en']);
        $firstEditor = Livewire::test(EditRatingQuestion::class, ['record' => $draft->id]);
        $staleEditor = Livewire::test(EditRatingQuestion::class, ['record' => $draft->id]);
        $firstEditor->set('data.label.en', 'First saved')->call('save')->assertHasNoErrors();
        $staleEditor->set('data.label.en', 'Stale overwrite')->call('save')->assertHasErrors(['data']);
        $this->assertSame('First saved', $draft->fresh('questions')->questions->first()->label['en']);
        $this->assertDatabaseCount('survey_versions', 2);
    }

    public function test_full_page_requires_edit_permission_and_valid_reason_labels(): void
    {
        $version = $this->publish(false);
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['survey.read']]);
        $this->actingAs($viewer)->get(RatingQuestionResource::getUrl('create'))->assertForbidden();
        $this->get(RatingQuestionResource::getUrl('edit', ['record' => $version]))->assertForbidden();
        $this->actingAs($this->staff);
        Livewire::test(EditRatingQuestion::class, ['record' => $version->id])
            ->call('editReasons', 5)->set('reasonData.items', [['code' => 'blank', 'label' => ['en' => '', 'lo' => '']]])
            ->call('applyReasons')->assertHasErrors(['reasonData.items.0.label.en', 'reasonData.items.0.label.lo']);
        $this->assertDatabaseCount('survey_versions', 1);
    }
}
