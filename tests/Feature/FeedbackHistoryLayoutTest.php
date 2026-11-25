<?php

namespace Tests\Feature;

use App\Filament\Resources\FeedbackResource;
use App\Filament\Resources\Pages\ListFeedback;
use App\Filament\Resources\Pages\ViewFeedback;
use App\Jobs\BuildFeedbackExport;
use App\Models\ExportJob;
use App\Models\FeedbackResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

class FeedbackHistoryLayoutTest extends FeedbackTest
{
    protected function draft(): array
    {
        $draft = parent::draft();
        $draft['questions'][] = ['code' => 'support-rating', 'type' => 'rating', 'label' => ['en' => 'Support rating', 'lo' => 'ຄະແນນການບໍລິການ'],
            'required' => false, 'max_length' => 100, 'max_choices' => 1, 'options' => []];

        return $draft;
    }

    private function history(): array
    {
        $this->freezeTime();
        $first = $this->payload('history-first', 'history-a');
        $first['metadata'] = ['os' => 'ios'];
        $text = $this->survey->questions()->where('type', 'text')->firstOrFail();
        $first['answers'][] = ['question_id' => $text->id, 'text_value' => '<script>first-history-comment</script>'];
        $first['answers'][] = ['question_id' => $this->survey->questions()->where('code', 'support-rating')->firstOrFail()->id, 'rating_value' => 4];
        $a = $this->api($first, 'history-first')->assertCreated()->json('response_id');
        FeedbackResponse::findOrFail($a)->update(['customer_name' => 'Private history name', 'customer_phone' => '02000123456']);
        $this->travel(1)->second();
        $second = $this->payload('history-second', 'history-a');
        $second['answers'][0]['rating_value'] = 2;
        $second['metadata'] = ['os' => 'android'];
        $b = $this->api($second, 'history-second')->assertCreated()->json('response_id');
        $this->travel(1)->second();
        $invitation = $this->withHeaders(['Authorization' => 'Bearer test-service-token', 'X-Service-ID' => 'app-backend', 'Idempotency-Key' => 'history-invitation'])
            ->postJson('/internal/v1/feedback/eligibility', [
                'actor_user_id' => 'history-b', 'survey_version_id' => $this->survey->id,
                'transaction_context' => ['transaction_id' => 'history-buy', 'transaction_type' => 'BUY_GOLD',
                    'transaction_occurred_at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'verified_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'success_verified' => true],
            ])->assertCreated()->json('invitation_id');
        $popup = $this->payload('history-popup', 'history-b');
        $popup['source'] = 'auto_prompt';
        $popup['invitation_id'] = $invitation;
        $c = $this->api($popup, 'history-popup')->assertCreated()->json('response_id');

        return [FeedbackResponse::findOrFail($a), FeedbackResponse::findOrFail($b), FeedbackResponse::findOrFail($c)];
    }

    public function test_history_layout_keeps_each_submission_and_filters_source_rating_dates_and_advanced_fields(): void
    {
        [$a, $b, $popup] = $this->history();
        $this->actingAs($this->staff);
        $this->withSession(['feedback_locale' => 'lo'])->get(FeedbackResource::getUrl())->assertOk()
            ->assertSee('ປະຫວັດໃຫ້ຄະແນນ ແລະ ຄຳຄິດເຫັນ');
        $page = Livewire::test(ListFeedback::class)->assertCanSeeTableRecords([$a, $b, $popup])->assertSee('4 / 5')->assertSee('5 / 5')
            ->assertSee('Private history name')->assertSee('02000123456');
        $page->set('filters.rating', 5)->assertCanSeeTableRecords([$a, $popup])->assertCanNotSeeTableRecords([$b]);
        $page->set('filters.rating', null)->call('setFeedbackSource', 'always_on')
            ->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$popup]);
        $page->call('setFeedbackSource', 'auto_prompt')->assertCanSeeTableRecords([$popup])->assertCanNotSeeTableRecords([$a, $b]);
        $page->call('setFeedbackSource', '')->set('filters.os', 'ios')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $popup]);
        $page->set('filters.os', null)->set('tableSearch', 'history-a')->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$popup]);
        $page->set('tableSearch', '')->set('filters.transaction_type', 'BUY_GOLD')->assertCanSeeTableRecords([$popup])->assertCanNotSeeTableRecords([$a, $b]);
        $page->set('filters.transaction_type', null)->set('filters.from', now()->addDay()->toDateString())->assertHasErrors('filters.until')
            ->set('filters.until', now()->addDay()->toDateString())->assertCanNotSeeTableRecords([$a, $b, $popup]);
    }

    public function test_history_details_open_a_page_for_the_selected_response_and_keep_list_filters(): void
    {
        [$a, $b] = $this->history();
        $this->actingAs($this->staff);
        $page = Livewire::test(ListFeedback::class)->set('tableSearch', 'history-a')->set('filters.rating', 5);
        $url = $page->instance()->getTable()->getAction('details')->record($a)->getUrl();
        $this->assertStringContainsString('/admin/feedback/'.$a->id, $url);
        $this->assertStringContainsString('search=history-a', $url);
        $this->assertSame($url, $page->instance()->getTable()->getRecordUrl($a));

        $this->get($url)->assertOk()->assertSee('Private history name')->assertSee('02000123456')->assertSee('5 / 5')
            ->assertSee('&lt;script&gt;first-history-comment&lt;/script&gt;', false)->assertDontSee('<script>first-history-comment</script>', false)
            ->assertSee('search=history-a', false);
        $this->get(FeedbackResource::getUrl('view', ['record' => $b->id]))->assertOk()->assertSee('2 / 5')->assertDontSee('first-history-comment');
    }

    public function test_history_export_freezes_the_searched_and_filtered_responses(): void
    {
        [$a] = $this->history();
        Queue::fake([BuildFeedbackExport::class]);
        $this->actingAs($this->staff);

        Livewire::test(ListFeedback::class)->set('tableSearch', 'history-a')->set('filters.rating', 5)
            ->call('exportHistory', 'xlsx')->assertNotified();

        $job = ExportJob::firstOrFail();
        $this->assertSame('xlsx', $job->format);
        $this->assertSame([$a->id], DB::table('export_job_responses')->where('export_job_id', $job->id)->pluck('feedback_response_id')->all());
        $this->api($this->payload('history-later', 'history-a'), 'history-later')->assertCreated();
        $this->assertSame([$a->id], DB::table('export_job_responses')->where('export_job_id', $job->id)->pluck('feedback_response_id')->all());
    }

    public function test_history_permissions_hide_identity_and_reject_direct_export_and_detail_access(): void
    {
        [$a] = $this->history();
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['feedback.read']]);
        $this->actingAs($viewer);
        Livewire::test(ListFeedback::class)->assertCanSeeTableRecords([$a])->assertTableColumnHidden('customer_reference')
            ->assertTableColumnHidden('customer_name')->assertTableColumnHidden('customer_phone')
            ->assertDontSee('Private history name')->assertDontSee('02000123456')->assertDontSee('Export Excel');
        $url = FeedbackResource::getUrl('view', ['record' => $a->id]);
        $this->get($url)->assertOk()->assertDontSee('Private history name')->assertDontSee('02000123456');
        Livewire::test(ViewFeedback::class, ['record' => $a->id])->assertDontSee('Private history name')->assertDontSee('02000123456');
        Livewire::test(ListFeedback::class)->call('exportHistory', 'xlsx')->assertForbidden();
        $this->assertDatabaseCount('export_jobs', 0);
        $viewer->update(['permissions' => ['customers.read', 'customer_identity.read', 'report.export']]);
        $this->get(FeedbackResource::getUrl())->assertForbidden();
        $this->get($url)->assertForbidden();
    }
}
