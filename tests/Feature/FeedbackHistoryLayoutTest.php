<?php

namespace Tests\Feature;

use App\Application\Analytics;
use App\Filament\Pages\CustomerDetail;
use App\Filament\Pages\Customers;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FeedbackCenter;
use App\Filament\Resources\FeedbackResource;
use App\Filament\Resources\Pages\ListFeedback;
use App\Filament\Resources\Pages\ViewFeedback;
use App\Jobs\BuildFeedbackExport;
use App\Models\ExportJob;
use App\Models\FeedbackResponse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

class FeedbackHistoryLayoutTest extends FeedbackTest
{
    public function test_dashboard_png_action_dispatches_capture_and_renders_chart_without_creating_export_job(): void
    {
        $this->history();
        $this->actingAs($this->staff);
        Livewire::test(Dashboard::class)->assertActionVisible('exportPng')
            ->callAction('exportPng')->assertDispatched('dashboard-export-png')->assertHasNoActionErrors();
        $this->assertDatabaseCount('export_jobs', 0);
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('data-dashboard-capture', false)
            ->assertSee('data-dashboard-export-button', false)->assertSee('data-chart-type="line"', false);
    }

    public function test_dashboard_png_button_requires_report_and_feedback_permissions(): void
    {
        foreach ([['analytics.read', 'feedback.read'], ['analytics.read', 'report.export']] as $permissions) {
            $viewer = User::factory()->create(['active' => true, 'permissions' => $permissions]);
            $this->actingAs($viewer);
            Livewire::test(Dashboard::class)->assertActionHidden('exportPng');
            $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('data-dashboard-export-button', false);
        }
    }

    public function test_dashboard_gender_counts_unique_accounts_and_uses_latest_reported_gender_within_filters(): void
    {
        [$first, $latest, $popup] = $this->history();
        $first->update(['gender' => 'Male']);
        $latest->update(['gender' => 'Female']);
        $popup->update(['gender' => 'Male']);
        $this->travel(1)->second();
        $this->api($this->payload('gender-missing-later', 'history-a'), 'gender-missing-later')->assertCreated();
        $this->api($this->payload('gender-unknown', 'unknown-account'), 'gender-unknown')->assertCreated();
        $demo = $this->payload('gender-demo', 'demo-account');
        $demo['gender'] = 'Female';
        $demoId = $this->api($demo, 'gender-demo')->assertCreated()->json('response_id');
        FeedbackResponse::findOrFail($demoId)->update(['caller' => 'demo']);
        $oldId = $this->api($this->payload('gender-old', 'old-account'), 'gender-old')->assertCreated()->json('response_id');
        FeedbackResponse::findOrFail($oldId)->update(['gender' => 'Male', 'received_at' => now()->subDays(31)]);
        $filters = ['exclude_demo' => true, 'from' => now('Asia/Vientiane')->toDateString(), 'until' => now('Asia/Vientiane')->toDateString()];
        $analytics = app(Analytics::class);

        $result = $analytics->respondentGenders($this->staff, $filters);

        $this->assertSame(3, $result['total']);
        $this->assertSame(['Female' => 1, 'Male' => 1, 'Unspecified' => 1], $result['counts']);
        $this->assertSame(['Female' => 33.3, 'Male' => 33.3, 'Unspecified' => 33.3], $result['percentages']);
        $this->assertSame($analytics->summary($this->staff, $filters)['unique_customers'], $result['total']);
        $this->assertSame(['Female' => 0, 'Male' => 1, 'Unspecified' => 0], $analytics->respondentGenders($this->staff, $filters + ['source' => 'auto_prompt'])['counts']);
        $this->assertSame(['Female' => 1, 'Male' => 0, 'Unspecified' => 0], $analytics->respondentGenders($this->staff, $filters + ['actor_user_id' => 'history-a'])['counts']);
        $this->assertSame(['Female' => 0, 'Male' => 2, 'Unspecified' => 1], $analytics->respondentGenders($this->staff, $filters + ['rating' => 5])['counts']);
        $this->actingAs($this->staff);
        $page = Livewire::test(Dashboard::class)->set('filters', $filters);
        $this->assertSame($result, $page->instance()->respondentGenders());
        $page->assertSee(__('feedback.respondent_gender_title'))->assertSee(__('feedback.gender_values.Female'))
            ->assertSee(__('feedback.gender_values.Male'))->assertSee(__('feedback.gender_unspecified'))->assertSee('33.3%');
    }

    public function test_dashboard_gender_chart_handles_empty_and_unknown_data_without_identity_permission(): void
    {
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['analytics.read']]);
        $analytics = app(Analytics::class);
        $this->assertSame(['total' => 0, 'counts' => ['Female' => 0, 'Male' => 0, 'Unspecified' => 0],
            'percentages' => ['Female' => null, 'Male' => null, 'Unspecified' => null]], $analytics->respondentGenders($viewer, []));
        $this->actingAs($viewer)->get(Dashboard::getUrl())->assertOk()->assertSee(__('feedback.no_data'));
        $this->api($this->payload('legacy-gender', 'legacy-account'), 'legacy-gender')->assertCreated();
        $result = $analytics->respondentGenders($viewer, []);
        $this->assertSame(['Female' => 0, 'Male' => 0, 'Unspecified' => 1], $result['counts']);
        $this->assertSame(100.0, $result['percentages']['Unspecified']);
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('100.0%')->assertDontSee('legacy-account');
    }

    public function test_gender_summary_requires_analytics_permission(): void
    {
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['feedback.read']]);
        $this->expectException(AuthorizationException::class);
        app(Analytics::class)->respondentGenders($viewer, []);
    }

    public function test_staff_ui_defaults_to_lao_and_keeps_explicit_language_selection(): void
    {
        $this->assertSame('lo', config('feedback.locale'));
        $this->actingAs($this->staff)->get(Dashboard::getUrl())->assertOk()
            ->assertSee('lang="lo"', false)->assertSee('ອອກຈາກລະບົບ');

        $this->get(route('staff.locale', 'en'))->assertRedirect('/admin')->assertSessionHas('feedback_locale', 'en');
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('lang="en"', false)->assertSee('Sign out');
        $this->get(route('staff.locale', 'lo'))->assertRedirect('/admin')->assertSessionHas('feedback_locale', 'lo');
        $this->get(Customers::getUrl())->assertOk()->assertSee('lang="lo"', false)->assertSee('ອອກຈາກລະບົບ');

        $this->withSession(['feedback_locale' => 'invalid'])->get(FeedbackResource::getUrl())->assertOk()
            ->assertSee('lang="lo"', false);
    }

    public function test_dashboard_tabs_keep_existing_urls_and_dashboard_sidebar_selected(): void
    {
        [$response] = $this->history();
        $this->actingAs($this->staff);
        $tabs = [Dashboard::getUrl(), Customers::getUrl(), FeedbackResource::getUrl()];

        foreach ($tabs as $active => $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            $xpath = new \DOMXPath($document);
            $links = $xpath->query('//nav[contains(@class,"eg-dashboard-tabs")]/a');
            $this->assertCount(3, $links);
            foreach ($tabs as $index => $target) {
                $this->assertSame($target, $links[$index]->getAttribute('href'));
                $this->assertSame($active === $index ? 'page' : '', $links[$index]->getAttribute('aria-current'));
            }
            if ($active > 0) {
                $parent = $xpath->query('//nav[contains(@class,"eg-customer-detail-breadcrumb")]/a');
                $current = $xpath->query('//nav[contains(@class,"eg-customer-detail-breadcrumb")]/span[@aria-current="page"]');
                $this->assertCount(1, $parent);
                $this->assertSame(Dashboard::getUrl(), $parent[0]->getAttribute('href'));
                $this->assertSame(trim($links[0]->textContent), trim($parent[0]->textContent));
                $this->assertCount(1, $current);
                $this->assertSame(trim($links[$active]->textContent), trim($current[0]->textContent));
            }
            $selected = $xpath->query('//li[contains(concat(" ",normalize-space(@class)," ")," fi-active ")]/a[contains(@class,"fi-sidebar-item-btn")]');
            $this->assertCount(1, $selected);
            $this->assertSame(Dashboard::getUrl(), $selected[0]->getAttribute('href'));
        }

        $member = app(\App\Application\Customers::class)->listing($this->staff, ['source' => 'auto_prompt'], answeredOnly: true)->firstOrFail();
        foreach ([
            FeedbackResource::getUrl('view', ['record' => $response->id]),
            CustomerDetail::getUrl(['record' => $member->id, 'filters' => ['source' => 'auto_prompt']]),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            $xpath = new \DOMXPath($document);
            $selected = $xpath->query('//li[contains(concat(" ",normalize-space(@class)," ")," fi-active ")]/a[contains(@class,"fi-sidebar-item-btn")]');
            $this->assertCount(1, $selected);
            $this->assertSame(Dashboard::getUrl(), $selected[0]->getAttribute('href'));
        }
    }

    public function test_feedback_only_viewer_keeps_feedback_center_sidebar_selected(): void
    {
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['feedback.read']]);
        $html = $this->actingAs($viewer)->get(FeedbackResource::getUrl())->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $selected = (new \DOMXPath($document))->query('//li[contains(concat(" ",normalize-space(@class)," ")," fi-active ")]/a[contains(@class,"fi-sidebar-item-btn")]');
        $this->assertCount(1, $selected);
        $this->assertSame(FeedbackCenter::getUrl(), $selected[0]->getAttribute('href'));
        $this->get(Dashboard::getUrl())->assertForbidden();
        $this->get(Customers::getUrl())->assertForbidden();
    }

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

    public function test_history_layout_only_shows_form_submissions_and_filters_rating_dates_and_advanced_fields(): void
    {
        [$a, $b, $popup] = $this->history();
        $this->actingAs($this->staff);
        $this->withSession(['feedback_locale' => 'lo'])->get(FeedbackResource::getUrl())->assertOk()
            ->assertSee('ປະຫວັດໃຫ້ຄະແນນ ແລະ ຄຳຄິດເຫັນ');
        $page = Livewire::test(ListFeedback::class)->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$popup])->assertSee('4 / 5')->assertSee('5 / 5')
            ->assertSee('Private history name')->assertSee('02000123456');
        $page->set('filters.rating', 5)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $popup]);
        $page->set('filters.rating', null)->set('filters.source', 'auto_prompt')
            ->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$popup]);
        $this->assertSame('always_on', $page->instance()->dataFilters()['source']);
        $page->set('filters.os', 'ios')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $popup]);
        $page->set('filters.os', null)->set('tableSearch', '020 0012-3456')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $popup]);
        $page->set('tableSearch', 'history-a')->assertCanNotSeeTableRecords([$a, $b, $popup]);
        $page->set('tableSearch', '')->set('filters.transaction_type', 'BUY_GOLD')->assertCanNotSeeTableRecords([$a, $b, $popup]);
        $page->set('filters.transaction_type', null)->set('filters.from', now()->addDay()->toDateString())->assertHasErrors('filters.until')
            ->set('filters.until', now()->addDay()->toDateString())->assertCanNotSeeTableRecords([$a, $b, $popup]);
    }

    public function test_history_details_open_a_page_for_the_selected_response_and_keep_list_filters(): void
    {
        [$a, $b] = $this->history();
        $this->actingAs($this->staff);
        $page = Livewire::test(ListFeedback::class)->set('tableSearch', '020 0012-3456')->set('filters.rating', 5);
        $url = $page->instance()->getTable()->getAction('details')->record($a)->getUrl();
        $this->assertStringContainsString('/admin/feedback/'.$a->id, $url);
        $this->assertStringContainsString('search=020%200012-3456', $url);
        $this->assertSame($url, $page->instance()->getTable()->getRecordUrl($a));

        $this->get($url)->assertOk()->assertSee('Private history name')->assertSee('02000123456')->assertSee('5 / 5')
            ->assertSee('&lt;script&gt;first-history-comment&lt;/script&gt;', false)->assertDontSee('<script>first-history-comment</script>', false)
            ->assertSee('search=020%200012-3456', false);
        $this->get(FeedbackResource::getUrl('view', ['record' => $b->id]))->assertOk()->assertSee('2 / 5')->assertDontSee('first-history-comment');
    }

    public function test_history_export_freezes_the_searched_and_filtered_responses(): void
    {
        [$a] = $this->history();
        Queue::fake([BuildFeedbackExport::class]);
        $this->actingAs($this->staff);

        Livewire::test(ListFeedback::class)->set('tableSearch', '020 0012-3456')->set('filters.rating', 5)
            ->call('exportHistory', 'xlsx')->assertNotified();

        $job = ExportJob::firstOrFail();
        $this->assertSame('xlsx', $job->format);
        $this->assertSame('always_on', $job->filters['source']);
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
        Livewire::test(ListFeedback::class)->set('tableSearch', '02000123456')->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListFeedback::class)->call('exportHistory', 'xlsx')->assertForbidden();
        $this->assertDatabaseCount('export_jobs', 0);
        $viewer->update(['permissions' => ['customers.read', 'customer_identity.read', 'report.export']]);
        $this->get(FeedbackResource::getUrl())->assertForbidden();
        $this->get($url)->assertForbidden();
    }
}
