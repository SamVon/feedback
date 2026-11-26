<?php

namespace Tests\Feature;

use App\Application\Cohorts;
use App\Application\Customers as CustomerService;
use App\Filament\Pages\CustomerDetail;
use App\Filament\Pages\Customers;
use App\Filament\Resources\Pages\ListFeedback;
use App\Models\CohortMember;
use App\Models\FeedbackResponse;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class AnsweredCustomersTest extends FeedbackTest
{
    private function popupResponse(string $submission, string $actor): FeedbackResponse
    {
        $eligibility = $this->eligibilityData();
        $eligibility['actor_user_id'] = $actor;
        $eligibility['transaction_context']['transaction_id'] = 'transaction-'.$submission;
        $invitation = $this->withHeaders(['Authorization' => 'Bearer test-service-token', 'X-Service-ID' => 'app-backend', 'Idempotency-Key' => 'invite-'.$submission])
            ->postJson('/internal/v1/feedback/eligibility', $eligibility)->assertCreated()->json('invitation_id');
        $payload = $this->payload($submission, $actor);
        $payload['source'] = 'auto_prompt';
        $payload['invitation_id'] = $invitation;
        $id = $this->api($payload, $submission)->assertCreated()->json('response_id');

        return FeedbackResponse::findOrFail($id);
    }

    public function test_answered_pages_work_without_user_service_or_a_customer_snapshot(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake();
        $answered = $this->popupResponse('answered-only', 'respondent');
        $empty = $answered->replicate(['invitation_id']);
        $empty->fill(['actor_user_id' => 'no-answers', 'client_submission_id' => 'empty-response'])->save();
        $newerEmpty = $answered->replicate(['invitation_id']);
        $newerEmpty->fill(['client_submission_id' => 'newer-empty-response', 'received_at' => now()->addSecond()])->save();
        $this->assertDatabaseCount('cohort_snapshots', 0);
        $this->actingAs($this->staff);
        $rows = app(CustomerService::class)->listing($this->staff, [], answeredOnly: true)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('respondent', $rows[0]->actor_user_id);
        $this->assertSame(1, (int) $rows[0]->response_count);
        $this->assertSame($answered->id, $rows[0]->latestFilteredResponse->id);
        Livewire::test(Customers::class)->assertCanSeeTableRecords($rows)->assertCountTableRecords(1)
            ->assertSee(__('feedback.answered'))->assertDontSee(__('feedback.no_snapshot'))
            ->assertDontSee(__('feedback.customer_unanswered_hint'))->assertDontSee(__('feedback.customer_data_source'));
        Livewire::test(ListFeedback::class)
            ->assertCanNotSeeTableRecords([$answered, $empty, $newerEmpty])->assertCountTableRecords(0);
        $detail = Livewire::test(CustomerDetail::class, ['record' => $rows[0]->id])->assertSee('5 / 5');
        $this->assertSame(1, $detail->instance()->responses->total());
        Http::assertNothingSent();
    }

    public function test_customer_page_excludes_imported_nonrespondents_and_applies_response_date_range(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake();
        $cohorts = app(Cohorts::class);
        [$snapshot] = $cohorts->start('user-adapter', 'answered-roster', [
            'external_id' => 'answered-roster', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expected_count' => 2, 'expected_batches' => 1, 'identity_type' => 'account',
        ]);
        $cohorts->members('user-adapter', 'answered-members', $snapshot['snapshot_id'], ['batch_id' => 'batch-1', 'members' => [
            ['actor_user_id' => 'answered', 'identity_complete' => true],
            ['actor_user_id' => 'unanswered', 'identity_complete' => true],
        ]]);
        $cohorts->complete('user-adapter', 'answered-complete', $snapshot['snapshot_id']);
        $this->popupResponse('answer', 'answered');
        $members = CohortMember::orderBy('actor_user_id')->get();
        $this->actingAs($this->staff);
        $page = Livewire::test(Customers::class)->assertCanSeeTableRecords([$members[0]])
            ->assertCanNotSeeTableRecords([$members[1]])->assertCountTableRecords(1);
        $tomorrow = now()->addDay()->toDateString();
        $page->set('filters.until', $tomorrow)->set('filters.from', $tomorrow)->assertCountTableRecords(0);
        Http::assertNothingSent();
    }

    public function test_customers_tab_counts_only_popup_answers_and_keeps_feedback_history_sources(): void
    {
        $this->freezeTime();
        $popup = $this->popupResponse('popup', 'popup-customer');
        $manual = FeedbackResponse::findOrFail($this->api($this->payload('manual', 'manual-customer'), 'manual')->assertCreated()->json('response_id'));
        $sameCustomerManual = FeedbackResponse::findOrFail($this->api($this->payload('mixed', 'popup-customer'), 'mixed')->assertCreated()->json('response_id'));
        $this->actingAs($this->staff);
        $rows = app(CustomerService::class)->listing($this->staff, ['source' => 'auto_prompt'], answeredOnly: true)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->response_count);
        $this->assertSame($popup->id, $rows[0]->latestFilteredResponse->id);
        $page = Livewire::test(Customers::class)->assertCanSeeTableRecords($rows)->assertCountTableRecords(1);
        $page->set('filters.source', 'always_on')->assertCanSeeTableRecords($rows)->assertCountTableRecords(1);
        $url = $page->instance()->getTable()->getAction('history')->record($rows[0])->getUrl();
        $this->assertStringContainsString('auto_prompt', $url);
        $this->get($url)->assertOk()->assertSee('5 / 5');
        $detail = Livewire::withQueryParams(['filters' => ['source' => 'auto_prompt']])
            ->test(CustomerDetail::class, ['record' => $rows[0]->id]);
        $this->assertSame(1, $detail->instance()->responses->total());
        $this->assertSame($popup->id, $detail->instance()->responses->first()->id);
        Livewire::test(ListFeedback::class)->assertCanSeeTableRecords([$manual, $sameCustomerManual])
            ->assertCanNotSeeTableRecords([$popup]);
    }
}
