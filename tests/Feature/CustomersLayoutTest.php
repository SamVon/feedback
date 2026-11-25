<?php

namespace Tests\Feature;

use App\Application\Cohorts;
use App\Application\CustomerRosterExport;
use App\Application\Customers as CustomerService;
use App\Filament\Pages\CustomerDetail;
use App\Filament\Pages\Customers;
use App\Models\CohortMember;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CustomersLayoutTest extends FeedbackTest
{
    public function test_layout_latest_import_preview_shows_profiles_without_replacing_verified_roster_or_using_failed_batch(): void
    {
        $this->roster();
        $this->api($this->payload('live-answer', 'new-live-customer'), 'live-answer')->assertCreated();
        $this->travel(1)->minute();
        $cohorts = app(Cohorts::class);
        [$import] = $cohorts->start('user-adapter', 'live-start', [
            'external_id' => 'live-import', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expected_count' => 3, 'expected_batches' => 1, 'identity_type' => 'account',
        ]);
        $cohorts->members('user-adapter', 'live-members', $import['snapshot_id'], ['batch_id' => 'batch-1', 'members' => [
            ['actor_user_id' => 'layout-a', 'identity_complete' => true],
            ['actor_user_id' => 'layout-b', 'identity_complete' => true],
            ['actor_user_id' => 'new-live-customer', 'display_name' => 'Live imported profile', 'identity_complete' => true],
        ]]);
        $this->travel(1)->minute();
        [$failed] = $cohorts->start('user-adapter', 'failed-start', [
            'external_id' => 'failed-import', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expected_count' => 1, 'expected_batches' => 2, 'identity_type' => 'account',
        ]);
        $cohorts->members('user-adapter', 'failed-members', $failed['snapshot_id'], ['batch_id' => 'batch-1', 'members' => [
            ['actor_user_id' => 'failed-account', 'display_name' => 'Failed partial profile', 'identity_complete' => true],
        ]]);
        $service = app(CustomerService::class);

        $state = $service->snapshot($this->staff, 'latest_import');

        $this->assertSame($import['snapshot_id'], $state['selected']->id);
        $this->assertSame('complete', $service->snapshot($this->staff)['selected']->status);
        $this->assertTrue($state['incomplete']);
        $this->assertSame('incomplete', $state['selected']->status);
        $default = $service->listing($this->staff, [])->where('cohort_members.actor_user_id', 'new-live-customer')->firstOrFail();
        $this->assertNull($default->display_name);
        $preview = $service->listing($this->staff, [], 'latest_import')->where('cohort_members.actor_user_id', 'new-live-customer')->firstOrFail();
        $this->assertSame('Live imported profile', $preview->display_name);
        $this->assertSame(1, (int) $preview->response_count);
        $this->actingAs($this->staff);
        $page = Livewire::test(Customers::class)->assertDontSee('Live imported profile')
            ->set('customerSource', 'latest_import')->assertCanSeeTableRecords([$preview])
            ->assertSee('Live imported profile')->assertSee(__('feedback.customer_import_preview_hint'))
            ->assertDontSee('Failed partial profile');
        $page->set('tableSearch', 'new-live-customer')->call('exportRoster', 'xlsx')->assertFileDownloaded('easy-gold-customers.xlsx');
        $export = app(CustomerRosterExport::class)->download($this->staff,
            $service->listing($this->staff, ['actor_user_id' => 'new-live-customer'], 'latest_import'), 'xlsx', 'latest_import');
        ob_start();
        $export->sendContent();
        $bytes = ob_get_clean();
        $file = tempnam(sys_get_temp_dir(), 'customer-preview-');
        try {
            file_put_contents($file, $bytes);
            $book = IOFactory::load($file);
            $this->assertSame('Live imported profile', $book->getActiveSheet()->getCell('B2')->getValue());
            $this->assertSame(__('feedback.incomplete'), $book->getActiveSheet()->getCell('D2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
        $url = $page->instance()->getTable()->getAction('history')->record($preview)->getUrl();
        $this->get($url)->assertOk()->assertSee('Live imported profile')->assertSee('5 / 5')->assertSee('customer_source=latest_import', false);
        $future = now()->addDay()->toDateString();
        $this->assertSame(0, (int) $service->listing($this->staff, ['from' => $future], 'latest_import')
            ->where('cohort_members.actor_user_id', 'new-live-customer')->firstOrFail()->response_count);

        $viewer = User::factory()->create(['active' => true, 'permissions' => ['customers.read', 'feedback.read']]);
        $this->actingAs($viewer)->get($url)->assertOk()->assertDontSee('Live imported profile');
        Livewire::test(Customers::class)->set('customerSource', 'latest_import')->assertDontSee('Live imported profile');
        $this->get(Customers::getUrl(['customer_source' => 'unsupported']))->assertUnprocessable();
    }

    public function test_layout_respondents_missing_from_snapshot_show_once_with_history_and_no_fake_profile(): void
    {
        $this->roster();
        $this->api($this->payload('new-customer-1', 'new-app-customer'), 'new-customer-1')->assertCreated();
        $this->api($this->payload('new-customer-2', 'new-app-customer'), 'new-customer-2')->assertCreated();
        $service = app(CustomerService::class);

        $rows = $service->listing($this->staff, [])->orderBy('cohort_members.actor_user_id')->get();

        $this->assertSame(['layout-a', 'layout-b', 'new-app-customer'], $rows->pluck('actor_user_id')->all());
        $new = $rows->last();
        $this->assertSame(2, (int) $new->response_count);
        $this->assertFalse($new->identity_complete);
        $this->assertNull($new->display_name);
        $this->assertNull($new->phone);
        $this->assertSame(5, $new->latestFilteredResponse->answers->first()->rating_value);
        $this->assertDatabaseCount('cohort_members', 2);
        $this->assertSame('complete', $service->snapshot($this->staff)['complete']->status);
        $this->actingAs($this->staff);
        $page = Livewire::test(Customers::class)->set('tableSearch', 'new-app-customer')
            ->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$rows->first()])
            ->assertSee(__('feedback.customer_profile_pending'));
        $url = $page->instance()->getTable()->getAction('history')->record($new)->getUrl();
        $this->get($url)->assertOk()->assertSee('new-app-customer')->assertSee('5 / 5')
            ->assertSee(__('feedback.customer_profile_pending'))->assertDontSee('Secret customer');
        $page->call('setCustomerStatus', 'incomplete')->assertCanSeeTableRecords([$new]);
        $page->call('setCustomerStatus', 'unanswered')->assertCanNotSeeTableRecords([$new]);
        $page->call('setCustomerStatus', '')->call('exportRoster', 'xlsx')->assertFileDownloaded('easy-gold-customers.xlsx');

        $viewer = User::factory()->create(['active' => true, 'permissions' => ['customers.read']]);
        $this->actingAs($viewer)->get($url)->assertForbidden();
        $limited = $service->listing($viewer, [])->where('cohort_members.actor_user_id', 'new-app-customer')->firstOrFail();
        $this->assertArrayNotHasKey('display_name', $limited->getAttributes());
        $this->assertArrayNotHasKey('latest_response_id', $limited->getAttributes());
        $viewer->update(['permissions' => ['customers.read', 'feedback.read']]);
        $this->get($url)->assertOk()->assertSee('new-app-customer')->assertSee('5 / 5');
    }

    public function test_layout_without_snapshot_shows_respondents_and_keeps_date_scope_in_history(): void
    {
        $this->freezeTime();
        $this->api($this->payload('before-sync', 'unsynced-customer'), 'before-sync')->assertCreated();
        $service = app(CustomerService::class);
        $future = now()->addDay()->toDateString();

        $new = $service->listing($this->staff, ['from' => $future])->firstOrFail();

        $this->assertSame('unsynced-customer', $new->actor_user_id);
        $this->assertSame(0, (int) $new->response_count);
        $this->assertNull($new->latestFilteredResponse);
        $this->assertDatabaseCount('cohort_members', 0);
        $this->actingAs($this->staff);
        Livewire::test(Customers::class)->assertCanSeeTableRecords([$new]);
        $this->get(CustomerDetail::getUrl(['record' => $new->id, 'filters' => ['from' => $future]]))
            ->assertOk()->assertSee(__('feedback.customer_no_response'))->assertDontSee('5 / 5');
        $detail = $service->detail($this->staff, $new, []);
        $this->assertNull($detail['snapshot_at']);
        $this->assertSame('incomplete', $detail['response_status']);
        $this->assertSame(1, $detail['response_count']);
        $this->get(CustomerDetail::getUrl(['record' => 'feedback:missing']))->assertNotFound();
    }

    public function test_layout_synced_profile_replaces_respondent_fallback_without_duplicates_or_broken_links(): void
    {
        $this->roster();
        $this->api($this->payload('awaiting-profile', 'new-app-customer'), 'awaiting-profile')->assertCreated();
        $service = app(CustomerService::class);
        $fallback = $service->listing($this->staff, [])->where('cohort_members.actor_user_id', 'new-app-customer')->firstOrFail();
        $cohorts = app(Cohorts::class);
        $this->travel(1)->minute();
        [$snapshot] = $cohorts->start('user-adapter', 'refreshed-start', [
            'external_id' => 'refreshed-roster', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expected_count' => 3, 'expected_batches' => 1, 'identity_type' => 'account',
        ]);
        $cohorts->members('user-adapter', 'refreshed-members', $snapshot['snapshot_id'], ['batch_id' => 'batch-1', 'members' => [
            ['actor_user_id' => 'layout-a', 'identity_complete' => true],
            ['actor_user_id' => 'layout-b', 'identity_complete' => true],
            ['actor_user_id' => 'new-app-customer', 'display_name' => 'Synced customer', 'identity_complete' => true],
        ]]);
        $cohorts->complete('user-adapter', 'refreshed-complete', $snapshot['snapshot_id']);

        $rows = $service->listing($this->staff, [])->get();

        $this->assertCount(3, $rows);
        $customer = $rows->firstWhere('actor_user_id', 'new-app-customer');
        $this->assertSame('Synced customer', $customer->display_name);
        $this->assertTrue($customer->identity_complete);
        $this->assertSame(1, (int) $customer->response_count);
        $this->actingAs($this->staff)->get(CustomerDetail::getUrl(['record' => $fallback->id]))
            ->assertOk()->assertSee('Synced customer')->assertSee('5 / 5')
            ->assertDontSee(__('feedback.customer_profile_pending'));
    }

    public function test_detail_pagination_with_five_responses_uses_filament_controls_and_keeps_profile(): void
    {
        $this->roster();
        foreach (range(2, 5) as $rating) {
            $this->travel(1)->second();
            $payload = $this->payload('pagination-'.$rating, 'layout-a');
            $payload['answers'][0]['rating_value'] = $rating;
            $this->api($payload, 'pagination-'.$rating)->assertCreated();
        }
        $member = CohortMember::where('actor_user_id', 'layout-a')->firstOrFail();
        $this->actingAs($this->staff);
        Livewire::test(CustomerDetail::class, ['record' => $member->id])
            ->assertSee('fi-pagination-overview', false)->assertSee('=Secret customer')->assertSee('5 / 5')
            ->call('nextPage', 'responsesPage')->assertSee('=Secret customer')->assertSee('4 / 5')
            ->call('previousPage', 'responsesPage')->assertSee('5 / 5')
            ->call('gotoPage', 5, 'responsesPage')->assertSee('=Secret customer')->assertSee('fi-pagination-overview', false);
    }

    public function test_detail_link_opens_full_page_with_grouped_saved_answers(): void
    {
        $this->roster();
        $this->travel(1)->second();
        $payload = $this->payload('detail-answer', 'layout-a');
        $payload['answers'][0]['rating_value'] = 4;
        $text = $this->survey->questions()->where('type', 'text')->firstOrFail();
        $choice = $this->survey->questions()->where('type', 'choice')->firstOrFail();
        $payload['answers'][] = ['question_id' => $text->id, 'text_value' => '<script>saved feedback</script>'];
        $payload['answers'][] = ['question_id' => $choice->id, 'option_ids' => [$choice->options->first()->id]];
        $this->api($payload, 'detail-answer')->assertCreated();
        $member = CohortMember::where('actor_user_id', 'layout-a')->firstOrFail();
        $this->actingAs($this->staff);
        $url = CustomerDetail::getUrl(['record' => $member->id]);
        $this->get($url)->assertOk()->assertSee(__('feedback.customer_detail_title'))->assertSee('=Secret customer')
            ->assertSee('4 / 5')->assertSee(__('feedback.customer_other_answers'))
            ->assertSee('&lt;script&gt;saved feedback&lt;/script&gt;', false)->assertDontSee('<script>saved feedback</script>', false);
        $page = Livewire::test(Customers::class);
        $link = $page->instance()->getTable()->getAction('history')->record($member)->getUrl();
        $this->assertStringContainsString('/admin/customers/'.$member->id, $link);
        $this->assertStringContainsString('filters', $link);
        Livewire::test(CustomerDetail::class, ['record' => $member->id])
            ->call('setPage', 2, 'responsesPage')->assertDontSee('saved feedback')->assertSee('5 / 5');
    }

    public function test_detail_page_keeps_customer_scope_and_date_filter_and_shows_empty_state(): void
    {
        $this->roster();
        $this->actingAs($this->staff);
        $a = CohortMember::where('actor_user_id', 'layout-a')->firstOrFail();
        $b = CohortMember::where('actor_user_id', 'layout-b')->firstOrFail();
        $this->get(CustomerDetail::getUrl(['record' => $b->id, 'filters' => ['actor_user_id' => 'layout-a']]))
            ->assertOk()->assertSee(__('feedback.customer_no_response'))->assertDontSee('5 / 5');
        $this->get(CustomerDetail::getUrl(['record' => $a->id, 'filters' => ['from' => now()->addDay()->toDateString()]]))
            ->assertOk()->assertSee(__('feedback.customer_no_response'))->assertDontSee('5 / 5');
        $this->get(CustomerDetail::getUrl(['record' => 'missing']))->assertNotFound();
    }

    public function test_detail_page_protects_identity_and_checks_direct_route_permissions(): void
    {
        $this->roster();
        $member = CohortMember::where('actor_user_id', 'layout-a')->firstOrFail();
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['customers.read', 'feedback.read']]);
        $url = CustomerDetail::getUrl(['record' => $member->id]);
        $this->actingAs($viewer)->get($url)->assertOk()->assertDontSee('Secret customer')->assertSee('layout-a');
        Livewire::test(CustomerDetail::class, ['record' => $member->id])->assertDontSee('Secret customer');
        $viewer->update(['permissions' => ['customers.read']]);
        $this->get($url)->assertForbidden();
        $viewer->update(['permissions' => ['feedback.read']]);
        $this->get($url)->assertForbidden();
    }

    private function roster(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $cohorts = app(Cohorts::class);
        [$snapshot] = $cohorts->start('user-adapter', 'layout-start', [
            'external_id' => 'layout-roster', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expected_count' => 2, 'expected_batches' => 1, 'identity_type' => 'account',
        ]);
        $cohorts->members('user-adapter', 'layout-members', $snapshot['snapshot_id'], ['batch_id' => 'batch-1', 'members' => [
            ['actor_user_id' => 'layout-a', 'display_name' => '=Secret customer', 'identity_complete' => true],
            ['actor_user_id' => 'layout-b', 'display_name' => 'Customer B', 'identity_complete' => true],
        ]]);
        $cohorts->complete('user-adapter', 'layout-complete', $snapshot['snapshot_id']);
        $this->api($this->payload('layout-answer', 'layout-a'), 'layout-answer')->assertCreated();
    }

    public function test_layout_status_tabs_search_and_export_share_customer_filters(): void
    {
        $this->roster();
        $this->actingAs($this->staff);
        $members = CohortMember::orderBy('actor_user_id')->get();
        $page = Livewire::test(Customers::class)->assertSee('Export Excel')->assertSee('Export PDF')->assertCanSeeTableRecords($members);
        $page->call('setCustomerStatus', 'answered')->assertCanSeeTableRecords([$members[0]])->assertCanNotSeeTableRecords([$members[1]]);
        $page->call('exportRoster', 'xlsx')->assertFileDownloaded('easy-gold-customers.xlsx');
        $page->call('setCustomerStatus', 'unanswered')->assertCanSeeTableRecords([$members[1]])->assertCanNotSeeTableRecords([$members[0]]);
        $page->call('setCustomerStatus', '')->set('tableSearch', 'layout-b')->assertCanSeeTableRecords([$members[1]])->assertCanNotSeeTableRecords([$members[0]]);
    }

    public function test_layout_roster_exports_use_literal_cells_and_pdf_output(): void
    {
        $this->roster();
        $query = app(CustomerService::class)->listing($this->staff, [])->where('cohort_members.actor_user_id', 'layout-a');
        $response = app(CustomerRosterExport::class)->download($this->staff, $query, 'xlsx');
        ob_start();
        $response->sendContent();
        $bytes = ob_get_clean();
        $file = tempnam(sys_get_temp_dir(), 'customers-export-');
        try {
            file_put_contents($file, $bytes);
            $book = IOFactory::load($file);
            $sheet = $book->getActiveSheet();
            $this->assertSame(2, $sheet->getHighestRow());
            $this->assertSame('=Secret customer', $sheet->getCell('B2')->getValue());
            $this->assertSame('s', $sheet->getCell('B2')->getDataType());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
        $response = app(CustomerRosterExport::class)->download($this->staff, $query, 'pdf');
        ob_start();
        $response->sendContent();
        $pdf = ob_get_clean();
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_layout_latest_answers_are_filtered_and_hidden_without_permission(): void
    {
        $this->roster();
        $row = app(CustomerService::class)->listing($this->staff, [])->firstOrFail();
        $this->assertSame(5, $row->latestFilteredResponse->answers->first()->rating_value);
        $future = now()->addDay()->toDateString();
        $this->assertNull(app(CustomerService::class)->listing($this->staff, ['from' => $future])->first()->latestFilteredResponse);
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['customers.read', 'report.export']]);
        $this->actingAs($viewer);
        Livewire::test(Customers::class)->assertDontSee('Secret customer')->assertTableColumnHidden('latest_feedback');
        $query = app(CustomerService::class)->listing($viewer, []);
        $this->assertArrayNotHasKey('latest_response_id', $query->first()->getAttributes());
    }
}
