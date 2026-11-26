<?php

namespace Tests\Feature;

use App\Application\Analytics;
use App\Application\Changes;
use App\Application\Cohorts;
use App\Application\Customers as CustomerService;
use App\Application\ReportFilters;
use App\Filament\Pages\Customers;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Pages\ListFeedback;
use App\Filament\Resources\Pages\ListOtherQuestions;
use App\Filament\Resources\Pages\ListRatingQuestions;
use App\Filament\Resources\Pages\ListRules;
use App\Jobs\BuildFeedbackExport;
use App\Models\CohortMember;
use App\Models\DailyFeedbackLimit;
use App\Models\ExportJob;
use App\Models\FeedbackAnswer;
use App\Models\FeedbackResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class DateTimeFiltersTest extends FeedbackTest
{
    private function minuteRange(): array
    {
        return ['from' => '2026-11-25 00:00:00', 'until' => '2026-11-25 00:00:00'];
    }

    private function timedResponses(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 25)->setTime(10, 0));
        $times = ['2026-11-24 16:59:59', '2026-11-24 17:00:00', '2026-11-24 17:00:59', '2026-11-24 17:01:00'];
        $responses = [];
        foreach ($times as $index => $time) {
            $response = FeedbackResponse::create([
                'caller' => 'test', 'client_submission_id' => 'time-'.$index, 'payload_hash' => str_repeat('a', 64),
                'actor_user_id' => in_array($index, [1, 2], true) ? 'time-a' : 'time-b', 'survey_version_id' => $this->survey->id,
                'source' => 'always_on', 'submitted_at' => $time, 'received_at' => $time,
            ]);
            FeedbackAnswer::create(['feedback_response_id' => $response->id,
                'question_id' => $this->survey->questions()->where('type', 'rating')->firstOrFail()->id, 'rating_value' => $index === 1 ? 4 : 5]);
            $responses[] = $response;
        }

        return $responses;
    }

    public function test_datetime_range_includes_selected_minute_converts_laos_time_once_and_keeps_legacy_days(): void
    {
        [$before, $start, $end, $after] = $this->timedResponses();
        $this->assertEqualsCanonicalizing([$start->id, $end->id], ReportFilters::query($this->minuteRange())->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$start->id, $end->id, $after->id], ReportFilters::query(['from' => '2026-11-25', 'until' => '2026-11-25'])->pluck('id')->all());
        $this->assertSame([$before->id], ReportFilters::query(['until' => '2026-11-24'])->pluck('id')->all());
        $this->assertSame(3, ReportFilters::query(['from' => '2026-11-25T00:00'])->count());
        $this->assertSame('2026-11-25 23:59:00', ReportFilters::validate(['until' => '2026-11-25'])['until']);
        $summary = app(Analytics::class)->summary($this->staff, $this->minuteRange() + $this->filters());
        $this->assertSame(2, $summary['response_count']);
        $this->assertSame(4.5, $summary['average']);
    }

    public function test_datetime_ranges_reject_invalid_dates_and_reversed_times_on_the_same_day(): void
    {
        foreach ([
            ['from' => '2026-11-25 15:31', 'until' => '2026-11-25 15:30'],
            ['from' => '2026-11-25 15:00', 'until' => '2026-11-25 25:00'],
            ['until' => '2026-02-30 15:30'],
        ] as $filters) {
            try {
                ReportFilters::validate($filters);
                $this->fail('Invalid date/time range accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('until', $exception->errors());
            }
        }
    }

    public function test_datetime_feedback_dashboard_reports_and_exports_keep_the_same_time_scope(): void
    {
        [$before, $start, $end, $after] = $this->timedResponses();
        $this->actingAs($this->staff);
        $page = Livewire::withQueryParams(['filters' => $this->minuteRange()])->test(ListFeedback::class)
            ->assertCanSeeTableRecords([$start, $end])->assertCanNotSeeTableRecords([$before, $after]);
        $this->assertSame($this->minuteRange(), $page->instance()->filtersForm->getState());
        $this->assertSame($this->minuteRange() + ['source' => 'always_on'], $page->instance()->dataFilters());
        $url = $page->instance()->getTable()->getAction('details')->record($start)->getUrl();
        $this->get($url)->assertOk();
        $dashboard = Livewire::test(Dashboard::class)->set('filters', $this->minuteRange());
        $this->assertSame(2, $dashboard->instance()->summary()['response_count']);
        $this->assertSame($this->minuteRange(), array_intersect_key($dashboard->instance()->filtersForm->getState(), $this->minuteRange()));
        Queue::fake();
        $reports = Livewire::test(Reports::class)->fillForm($this->minuteRange(), 'filtersForm');
        $reports->call('createExport', 'xlsx')->assertHasNoErrors();
        $job = ExportJob::firstOrFail();
        $this->assertSame($this->minuteRange(), array_intersect_key($job->filters, $this->minuteRange()));
        $this->assertEqualsCanonicalizing([$start->id, $end->id], DB::table('export_job_responses')->where('export_job_id', $job->id)->pluck('feedback_response_id')->all());
        $this->assertSame(2, $job->summary['response_count']);
        Queue::assertPushed(BuildFeedbackExport::class);
        $page->set('filters.from', '2026-11-25 00:01:00')->assertHasErrors('filters.until');
    }

    public function test_datetime_customer_answer_status_and_details_use_the_selected_time(): void
    {
        $this->timedResponses();
        $cohorts = app(Cohorts::class);
        [$snapshot] = $cohorts->start('user-adapter', 'time-start', ['external_id' => 'time-roster',
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expected_count' => 2, 'expected_batches' => 1, 'identity_type' => 'account']);
        $cohorts->members('user-adapter', 'time-members', $snapshot['snapshot_id'], ['batch_id' => 'batch-1', 'members' => [
            ['actor_user_id' => 'time-a', 'identity_complete' => true], ['actor_user_id' => 'time-b', 'identity_complete' => true],
        ]]);
        $cohorts->complete('user-adapter', 'time-complete', $snapshot['snapshot_id']);
        $rows = app(CustomerService::class)->listing($this->staff, $this->minuteRange())->get()->keyBy('actor_user_id');
        $this->assertSame(2, (int) $rows['time-a']->response_count);
        $this->assertSame(0, (int) $rows['time-b']->response_count);
        $this->actingAs($this->staff);
        $page = Livewire::withQueryParams(['filters' => $this->minuteRange()])->test(Customers::class)
            ->assertCanSeeTableRecords([$rows['time-a']])->assertCanNotSeeTableRecords([$rows['time-b']]);
        $this->assertSame($this->minuteRange(), array_intersect_key($page->instance()->filtersForm->getState(), $this->minuteRange()));
        $member = CohortMember::where('actor_user_id', 'time-a')->firstOrFail();
        $detail = app(CustomerService::class)->detail($this->staff, $member, $this->minuteRange());
        $this->assertSame(2, $detail['response_count']);
        $url = $page->instance()->getTable()->getAction('history')->record($member)->getUrl();
        $this->get($url)->assertOk();
    }

    public function test_datetime_rating_other_questions_and_rule_history_filters_use_minutes(): void
    {
        $this->actingAs($this->staff);
        $this->survey->update(['created_at' => '2026-11-24 16:59:59']);
        $inside = app(Changes::class)->surveyDraft($this->staff, $this->draft());
        $inside->update(['created_at' => '2026-11-24 17:00:59']);
        $outside = app(Changes::class)->surveyDraft($this->staff, $this->draft());
        $outside->update(['created_at' => '2026-11-24 17:01:00']);
        Livewire::test(ListRatingQuestions::class)->filterTable('history_dates', $this->minuteRange())
            ->assertCanSeeTableRecords([$inside])->assertCanNotSeeTableRecords([$this->survey, $outside]);
        Livewire::test(ListOtherQuestions::class)->filterTable('latest', false)->filterTable('history_dates', $this->minuteRange())
            ->assertCanSeeTableRecords($inside->questions()->whereIn('type', ['text', 'choice'])->get())
            ->assertCanNotSeeTableRecords($outside->questions()->whereIn('type', ['text', 'choice'])->get());
        $this->rule->update(['updated_at' => '2026-11-24 16:59:59']);
        $insideRule = app(Changes::class)->ruleDraft($this->staff, $this->rule->rules);
        $insideRule->update(['updated_at' => '2026-11-24 17:00:59']);
        $outsideRule = app(Changes::class)->ruleDraft($this->staff, $this->rule->rules);
        $outsideRule->update(['updated_at' => '2026-11-24 17:01:00']);
        Livewire::test(ListRules::class)->filterTable('edited_at', $this->minuteRange())
            ->assertCanSeeTableRecords([$insideRule])->assertCanNotSeeTableRecords([$this->rule, $outsideRule]);
        $insideLimit = DailyFeedbackLimit::create(['response_limit' => 3, 'editor_id' => $this->staff->id]);
        $insideLimit->forceFill(['created_at' => '2026-11-24 17:00:59'])->save();
        $outsideLimit = DailyFeedbackLimit::create(['response_limit' => 4, 'editor_id' => $this->staff->id]);
        $outsideLimit->forceFill(['created_at' => '2026-11-24 17:01:00'])->save();
        Livewire::withQueryParams(['tab' => 'daily-limit'])->test(ListRules::class)->filterTable('edited_at', $this->minuteRange())
            ->assertCanSeeTableRecords([$insideLimit])->assertCanNotSeeTableRecords([$outsideLimit]);
    }
}
