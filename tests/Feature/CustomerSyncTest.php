<?php

namespace Tests\Feature;

use App\Application\Cohorts;
use App\Application\Customers as CustomerService;
use App\Filament\Pages\Customers;
use App\Models\CohortMember;
use App\Models\CohortSnapshot;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Livewire\Livewire;

class CustomerSyncTest extends FeedbackTest
{
    private function syncSetup(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['feedback.customer_sync' => [
            'environment' => 'staging', 'user_environment' => 'staging',
            'user_url' => 'https://user.test', 'user_token' => 'read-only-token',
            'feedback_url' => 'https://feedback.test', 'cohort_token' => 'cohort-test',
        ]]);
    }

    private function exportFile(array $users): string
    {
        Storage::disk('local')->put('verified.json', json_encode([
            'snapshot_id' => 'immutable-export-1', 'environment' => 'staging', 'complete' => true,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'total' => count($users), 'data' => $users,
        ]));

        return Storage::disk('local')->path('verified.json');
    }

    private function fakeCohorts(bool &$failBatch): void
    {
        Http::fake(['https://feedback.test/internal/v1/feedback/*' => function (Request $request) use (&$failBatch) {
            $this->assertSame('Bearer cohort-test', $request->header('Authorization')[0]);
            $this->assertSame('user-adapter', $request->header('X-Service-ID')[0]);
            $key = $request->header('Idempotency-Key')[0];
            $path = parse_url($request->url(), PHP_URL_PATH);
            $cohorts = app(Cohorts::class);
            if (str_ends_with($path, '/cohort-snapshots')) {
                [$data, $code] = $cohorts->start('user-adapter', $key, $request->data());
            } elseif (str_ends_with($path, '/members')) {
                if ($failBatch) {
                    return Http::response([], 503);
                }
                $id = basename(dirname($path));
                [$data, $code] = $cohorts->members('user-adapter', $key, $id, $request->data());
            } else {
                $id = basename(dirname($path));
                [$data, $code] = $cohorts->complete('user-adapter', $key, $id);
            }

            return Http::response($data, $code);
        }]);
    }

    public function test_customers_sync_verified_export_retries_batch_and_preserves_minimal_identity(): void
    {
        $this->syncSetup();
        $file = $this->exportFile([
            ['id' => 'account-a', 'role' => 'CUSTOMER', 'first_name' => 'Customer', 'last_name' => 'A', 'password' => 'must-be-discarded', 'phone' => 'private-phone'],
            ['id' => 'account-b', 'role' => 'CUSTOMER'],
        ]);
        $fail = true;
        $this->fakeCohorts($fail);
        $run = (string) Str::uuid();
        $this->artisan('feedback:sync-customers', ['--run' => $run, '--snapshot-file' => $file])->assertFailed();
        $this->assertSame('incomplete', CohortSnapshot::firstOrFail()->status);
        $fail = false;
        $this->artisan('feedback:sync-customers', ['--run' => $run])->assertSuccessful();
        $this->artisan('feedback:sync-customers', ['--run' => $run])->assertSuccessful();
        $this->assertDatabaseCount('cohort_snapshots', 1);
        $this->assertDatabaseCount('cohort_members', 2);
        $this->assertDatabaseCount('cohort_batches', 1);
        $this->assertSame('complete', CohortSnapshot::first()->status);
        $member = CohortMember::where('actor_user_id', 'account-a')->firstOrFail();
        $this->assertSame('Customer A', $member->display_name);
        $this->assertNull($member->phone);
        $this->assertNull($member->one_id);
        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/members')) {
                return false;
            }
            $this->assertSame(['actor_user_id', 'display_name', 'identity_complete'], array_keys($request->data()['members'][0]));

            return true;
        });
    }

    public function test_customers_legacy_pages_stay_incomplete_even_with_matching_total(): void
    {
        $this->syncSetup();
        $fail = false;
        $this->fakeCohorts($fail);
        Http::fake(['https://user.test/user-service/api/v1/user/*' => function (Request $request) {
            $this->assertSame('CUSTOMER', $request['role']);
            $this->assertSame('Bearer read-only-token', $request->header('Authorization')[0]);
            $skip = (int) $request['skip'];

            return Http::response(['error' => false, 'total' => 2, 'data' => [['id' => 'account-'.$skip, 'role' => 'CUSTOMER']]]);
        }]);
        $this->artisan('feedback:sync-customers')->assertSuccessful();
        $this->assertSame('incomplete', CohortSnapshot::firstOrFail()->status);
        $this->assertDatabaseCount('cohort_members', 2);
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/complete'));
    }

    public function test_customers_sync_recovers_duplicate_pages_using_distinct_accounts_from_another_sort(): void
    {
        $this->syncSetup();
        $fail = false;
        $this->fakeCohorts($fail);
        $requests = [];
        Http::fake(['https://user.test/user-service/api/v1/user/*' => function (Request $request) use (&$requests) {
            $requests[] = [$request['order_by'], (int) $request['skip']];
            $ids = $request['order_by'] === 'updated_at' ? ['account-c']
                : ((int) $request['skip'] === 0 ? ['account-a', 'account-b'] : ['account-b']);

            return Http::response(['error' => false, 'total' => 3, 'data' => array_map(fn ($id) => [
                'id' => $id, 'role' => 'CUSTOMER', 'first_name' => 'Real profile', 'phone' => 'not-imported',
            ], $ids)]);
        }]);

        $this->artisan('feedback:sync-customers')->assertSuccessful();

        $this->assertSame([['created_at', 0], ['created_at', 2], ['updated_at', 0]], $requests);
        $this->assertSame(['account-a', 'account-b', 'account-c'], CohortMember::orderBy('actor_user_id')->pluck('actor_user_id')->all());
        $this->assertSame('Real profile', CohortMember::where('actor_user_id', 'account-c')->firstOrFail()->display_name);
        $this->assertNull(CohortMember::firstOrFail()->phone);
        $this->assertSame('incomplete', CohortSnapshot::firstOrFail()->status);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/complete'));
    }

    public function test_customers_sync_aborts_when_total_changes_during_duplicate_recovery(): void
    {
        $this->syncSetup();
        Http::fake(['https://user.test/user-service/api/v1/user/*' => fn (Request $request) => Http::response([
            'error' => false, 'total' => $request['order_by'] === 'created_at' ? 2 : 3,
            'data' => [['id' => 'same-account', 'role' => 'CUSTOMER']],
        ])]);

        $this->artisan('feedback:sync-customers')->assertFailed();

        $this->assertDatabaseCount('cohort_snapshots', 0);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'feedback.test'));
    }

    public function test_customers_partial_preview_is_opt_in_and_excludes_only_unreadable_records(): void
    {
        $this->syncSetup();
        $fail = false;
        $this->fakeCohorts($fail);
        Http::fake(['https://user.test/user-service/api/v1/user/*' => function (Request $request) {
            $offset = (int) $request['skip'];
            $limit = (int) $request['limit'];
            if ($offset <= 1 && $offset + $limit > 1) {
                return Http::response(['error' => true, 'msg' => 'error decoding key profile: private source details'], 500);
            }

            return Http::response(['error' => false, 'total' => 2, 'data' => array_slice([
                ['id' => 'readable-account', 'role' => 'CUSTOMER', 'first_name' => 'Readable profile'],
                ['id' => 'unreadable-account', 'role' => 'CUSTOMER'],
            ], $offset, $limit)]);
        }]);
        $run = (string) Str::uuid();
        $this->artisan('feedback:sync-customers', ['--run' => $run])->doesntExpectOutputToContain('private source details')->assertFailed();
        $this->assertDatabaseCount('cohort_snapshots', 0);

        $this->artisan('feedback:sync-customers', ['--run' => $run, '--allow-partial' => true])
            ->expectsOutput('Missing accounts: 1 of 2. Select Latest imported list (preview) in Customers to inspect readable profiles.')
            ->doesntExpectOutputToContain('private source details')->assertSuccessful();

        $this->assertDatabaseCount('cohort_members', 1);
        $this->assertSame('readable-account', CohortMember::firstOrFail()->actor_user_id);
        $this->assertSame('incomplete', CohortSnapshot::firstOrFail()->status);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/complete'));
    }

    public function test_customers_partial_preview_does_not_hide_source_outages(): void
    {
        $this->syncSetup();
        Sleep::fake();
        Http::fake(['https://user.test/user-service/api/v1/user/*' => Http::response(['error' => true, 'msg' => 'Temporary outage'], 500)]);

        $this->artisan('feedback:sync-customers', ['--allow-partial' => true])->assertFailed();

        $this->assertDatabaseCount('cohort_snapshots', 0);
        Http::assertSentCount(3);
    }

    public function test_customers_sync_resumes_encrypted_source_checkpoint_after_connection_failure(): void
    {
        $this->syncSetup();
        Sleep::fake();
        $failBatch = false;
        $this->fakeCohorts($failBatch);
        $failSource = true;
        $offsets = [];
        Http::fake(['https://user.test/user-service/api/v1/user/*' => function (Request $request) use (&$failSource, &$offsets) {
            $offset = (int) $request['skip'];
            $offsets[] = $offset;
            if ($offset === 1 && $failSource) {
                return Http::failedConnection();
            }

            return Http::response(['error' => false, 'total' => 2, 'data' => [
                ['id' => 'checkpoint-'.$offset, 'role' => 'CUSTOMER'],
            ]]);
        }]);
        $run = (string) Str::uuid();
        $this->artisan('feedback:sync-customers', ['--run' => $run])->assertFailed();
        $this->assertSame([0, 1, 1, 1], $offsets);
        $this->assertDatabaseCount('cohort_snapshots', 0);
        config(['feedback.customer_sync.user_url' => 'https://different-source.test']);
        $this->artisan('feedback:sync-customers', ['--run' => $run])
            ->expectsOutput('Customer sync failed. Resume must use the original source and target environment.')->assertFailed();
        config(['feedback.customer_sync.user_url' => 'https://user.test']);
        $failSource = false;

        $this->artisan('feedback:sync-customers', ['--run' => $run])->assertSuccessful();

        $this->assertSame([0, 1, 1, 1, 1], $offsets);
        $this->assertSame(['checkpoint-0', 'checkpoint-1'], CohortMember::orderBy('actor_user_id')->pluck('actor_user_id')->all());
        Storage::disk('local')->assertMissing('customer-sync/'.$run.'/reading/state.enc');
        Storage::disk('local')->assertExists('customer-sync/'.$run.'.enc');
    }

    public function test_customers_partial_preview_does_not_import_an_empty_roster_when_all_records_are_unreadable(): void
    {
        $this->syncSetup();
        Http::fake(['https://user.test/user-service/api/v1/user/*' => function (Request $request) {
            if ((int) $request['skip'] === 0) {
                return Http::response(['error' => true, 'msg' => 'error decoding key profile'], 500);
            }

            return Http::response(['error' => false, 'total' => 1, 'data' => []]);
        }]);

        $this->artisan('feedback:sync-customers', ['--allow-partial' => true])->assertFailed();

        $this->assertDatabaseCount('cohort_snapshots', 0);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'feedback.test'));
    }

    public function test_customers_verified_export_handles_multiple_batches_and_empty_roster(): void
    {
        $this->syncSetup();
        $fail = false;
        $this->fakeCohorts($fail);
        $users = array_map(fn (int $n): array => ['id' => 'bulk-'.$n, 'role' => 'CUSTOMER'], range(1, 501));
        $run = (string) Str::uuid();
        $file = $this->exportFile($users);
        $this->artisan('feedback:sync-customers', ['--run' => $run, '--snapshot-file' => $file])->assertSuccessful();
        Storage::disk('local')->delete('verified.json');
        $this->artisan('feedback:sync-customers', ['--run' => $run])->assertSuccessful();
        $this->assertDatabaseCount('cohort_members', 501);
        $this->assertDatabaseCount('cohort_batches', 2);
        $this->travel(1)->minute();
        $this->artisan('feedback:sync-customers', ['--snapshot-file' => $this->exportFile([])])->assertSuccessful();
        $snapshot = app(CustomerService::class)->snapshot($this->staff)['complete'];
        $this->assertSame(0, $snapshot->expected_count);
        $this->assertSame(0, $snapshot->members()->count());
    }

    public function test_customers_sync_refuses_read_auth_failure_and_insecure_remote_url(): void
    {
        $this->syncSetup();
        Http::fake(['https://user.test/user-service/api/v1/user/*' => Http::response([], 403)]);
        $this->artisan('feedback:sync-customers')
            ->expectsOutput('Customer sync failed. User Service returned HTTP 403. The read credential needs permission to list customers.')
            ->assertFailed();
        $this->assertDatabaseCount('cohort_snapshots', 0);
        config(['feedback.customer_sync.user_url' => 'http://user.test']);
        $this->artisan('feedback:sync-customers')->assertFailed();
        $this->assertDatabaseCount('cohort_members', 0);
    }

    public function test_customers_sync_check_lists_missing_settings_without_network_requests(): void
    {
        $this->syncSetup();
        config(['feedback.customer_sync.user_url' => null, 'feedback.customer_sync.user_token' => null]);
        $this->artisan('feedback:sync-customers', ['--check' => true])
            ->expectsOutput('Customer sync failed. Missing configuration: USER_SERVICE_BASE_URL, USER_SERVICE_READ_TOKEN')
            ->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('cohort_snapshots', 0);
    }

    public function test_customers_sync_check_accepts_export_configuration_without_source_token(): void
    {
        $this->syncSetup();
        config(['feedback.customer_sync.user_token' => null]);
        $this->artisan('feedback:sync-customers', ['--check' => true, '--snapshot-file' => '/private/verified.json'])
            ->expectsOutput('Customer sync configuration is valid. Connections and permissions have not been tested.')
            ->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_customers_sync_target_error_reports_status_without_echoing_body(): void
    {
        $this->syncSetup();
        $file = $this->exportFile([]);
        Http::fake(['https://feedback.test/internal/v1/feedback/*' => Http::response(['message' => 'private upstream data'], 401)]);
        $this->artisan('feedback:sync-customers', ['--snapshot-file' => $file])
            ->expectsOutput('Customer sync failed. Cohort API returned HTTP 401. Check FEEDBACK_COHORT_TOKEN against the target FEEDBACK_COHORT_TOKEN_SHA256.')
            ->doesntExpectOutputToContain('private upstream data')
            ->assertFailed();
    }

    public function test_customers_sync_rejects_environment_mismatch_and_duplicate_pages(): void
    {
        $this->syncSetup();
        config(['feedback.customer_sync.user_environment' => 'production']);
        $this->artisan('feedback:sync-customers')->assertFailed();
        Http::assertNothingSent();
        config(['feedback.customer_sync.user_environment' => 'staging']);
        Http::fake(['https://user.test/user-service/api/v1/user/*' => Http::response([
            'error' => false, 'total' => 2, 'data' => [['id' => 'duplicate', 'role' => 'CUSTOMER']],
        ])]);
        $this->artisan('feedback:sync-customers')->assertFailed();
        $this->assertDatabaseCount('cohort_snapshots', 0);
        Http::assertSentCount(6);
    }

    public function test_customers_table_includes_nonrespondents_and_filters_by_date_without_one_id(): void
    {
        $this->syncSetup();
        $fail = false;
        $this->fakeCohorts($fail);
        $file = $this->exportFile([
            ['id' => 'account-a', 'role' => 'CUSTOMER', 'first_name' => 'Visible name'],
            ['id' => 'account-b', 'role' => 'CUSTOMER'],
        ]);
        $this->artisan('feedback:sync-customers', ['--snapshot-file' => $file])->assertSuccessful();
        $this->api($this->payload('customer-answer', 'account-a'), 'customer-answer')->assertCreated();
        $this->actingAs($this->staff);
        $members = CohortMember::orderBy('actor_user_id')->get();
        $page = Livewire::test(Customers::class)->assertCanSeeTableRecords($members);
        $page->filterTable('response_status', 'answered')->assertCanSeeTableRecords([$members[0]])->assertCanNotSeeTableRecords([$members[1]]);
        $page->filterTable('response_status', 'unanswered')->assertCanSeeTableRecords([$members[1]])->assertCanNotSeeTableRecords([$members[0]]);
        $this->travel(2)->days();
        $page->set('filters.from', now()->toDateString())->assertHasErrors('filters.until')
            ->set('filters.until', now()->toDateString())
            ->assertCanSeeTableRecords($members);
        $rows = app(CustomerService::class)->listing($this->staff, ['from' => now()->toDateString()])->get();
        $this->assertSame([0, 0], $rows->pluck('response_count')->map(fn ($n) => (int) $n)->all());
        $this->assertNull($rows[0]->latest_at);
    }

    public function test_customers_failed_new_snapshot_keeps_complete_roster_and_restricts_identity(): void
    {
        $this->syncSetup();
        $fail = false;
        $this->fakeCohorts($fail);
        $file = $this->exportFile([['id' => 'existing-account', 'role' => 'CUSTOMER', 'first_name' => 'Hidden identity']]);
        $this->artisan('feedback:sync-customers', ['--snapshot-file' => $file])->assertSuccessful();
        $this->travel(1)->minute();
        $file = $this->exportFile([['id' => 'new-account', 'role' => 'CUSTOMER']]);
        $fail = true;
        $this->artisan('feedback:sync-customers', ['--snapshot-file' => $file])->assertFailed();
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['customers.read']]);
        $this->actingAs($viewer);
        $rows = app(CustomerService::class)->listing($viewer, [])->get();
        $this->assertSame(['existing-account'], $rows->pluck('actor_user_id')->all());
        $this->assertArrayNotHasKey('display_name', $rows[0]->getAttributes());
        Livewire::test(Customers::class)->assertDontSee('Hidden identity')->assertTableActionHidden('history', $rows[0]);
        $detail = app(CustomerService::class)->detail($viewer, $rows[0], []);
        $this->assertArrayNotHasKey('display_name', $detail);
        $this->assertArrayNotHasKey('latest_answers', $detail);
        $this->assertTrue(app(CustomerService::class)->snapshot($viewer)['incomplete']);
        $viewer->update(['permissions' => []]);
        $this->get('/admin/customers')->assertForbidden();
    }
}
