<?php

namespace Tests\Feature;

use App\Application\CustomerSyncException;
use App\Application\SyncCustomers;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerSyncLoadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Sleep::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['feedback.customer_sync' => [
            'live_enabled' => false, 'environment' => 'staging', 'user_environment' => 'staging',
            'user_url' => 'https://user.test', 'user_token' => 'read-token',
            'feedback_url' => 'https://feedback.test', 'cohort_token' => 'cohort-token',
        ]]);
        Http::fake(['https://feedback.test/*' => Http::response(['snapshot_id' => 'local-snapshot'], 201)]);
    }

    private function failure(string $run): string
    {
        try {
            app(SyncCustomers::class)->run($run);
        } catch (CustomerSyncException $exception) {
            return $exception->getMessage();
        }
        $this->fail('Expected the source read to stop.');
    }

    public function test_live_sync_is_disabled_without_making_requests(): void
    {
        $this->assertStringContainsString('Live User Service sync is disabled', $this->failure((string) Str::uuid()));
        Http::assertNothingSent();
    }

    public function test_verified_file_import_does_not_contact_user_service_when_live_sync_is_disabled(): void
    {
        Storage::disk('local')->put('export.json', json_encode([
            'environment' => 'staging', 'snapshot_id' => 'export', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'complete' => true, 'total' => 1, 'data' => [['id' => 'answered-customer', 'role' => 'CUSTOMER']],
        ]));
        $result = app(SyncCustomers::class)->run((string) Str::uuid(), Storage::disk('local')->path('export.json'));
        $this->assertTrue($result['complete']);
        $this->assertSame(1, $result['count']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'user.test'));
    }

    public function test_opted_in_source_requests_are_paced_and_journal_resume_does_not_read_source(): void
    {
        config(['feedback.customer_sync.live_enabled' => true, 'feedback.customer_sync.request_interval_ms' => 1]);
        Http::fake(['https://user.test/*' => fn (Request $request) => Http::response([
            'error' => false, 'total' => 2, 'data' => [['id' => 'account-'.$request['skip'], 'role' => 'CUSTOMER']],
        ])]);
        $run = (string) Str::uuid();
        $result = app(SyncCustomers::class)->run($run);
        $this->assertSame(2, $result['count']);
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMilliseconds == 1000, 2);
        config(['feedback.customer_sync.live_enabled' => false]);
        app(SyncCustomers::class)->run($run);
        $requests = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'user.test'));
        $this->assertCount(2, $requests);
    }

    public function test_duplicate_pages_do_not_trigger_additional_full_read_passes_by_default(): void
    {
        config(['feedback.customer_sync.live_enabled' => true]);
        Http::fake(['https://user.test/*' => Http::response([
            'error' => false, 'total' => 2, 'data' => [['id' => 'duplicate', 'role' => 'CUSTOMER']],
        ])]);
        $this->assertStringContainsString('after 1 read pass(es)', $this->failure((string) Str::uuid()));
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'feedback.test'));
    }

    public function test_overload_stops_without_retry_and_honors_retry_after_before_resuming_checkpoint(): void
    {
        config(['feedback.customer_sync.live_enabled' => true]);
        $available = false;
        $offsets = [];
        Http::fake(['https://user.test/*' => function (Request $request) use (&$available, &$offsets) {
            $offsets[] = (int) $request['skip'];
            if ((int) $request['skip'] === 1 && ! $available) {
                return Http::response([], 429, ['Retry-After' => '300']);
            }

            return Http::response(['error' => false, 'total' => 2, 'data' => [['id' => 'account-'.$request['skip'], 'role' => 'CUSTOMER']]]);
        }]);
        $run = (string) Str::uuid();
        $this->assertStringContainsString('HTTP 429', $this->failure($run));
        $this->assertSame([0, 1], $offsets);
        $this->travel(61)->seconds();
        $this->assertStringContainsString('cooling down', $this->failure($run));
        $this->assertSame([0, 1], $offsets);
        $this->travel(240)->seconds();
        $available = true;
        $result = app(SyncCustomers::class)->run($run);
        $this->assertSame(2, $result['count']);
        $this->assertSame([0, 1, 1], $offsets);
    }

    public function test_service_outage_blocks_new_runs_without_repeated_requests(): void
    {
        config(['feedback.customer_sync.live_enabled' => true]);
        Http::fake(['https://user.test/*' => Http::response([], 503)]);
        $this->assertStringContainsString('HTTP 503', $this->failure((string) Str::uuid()));
        $this->assertStringContainsString('cooling down', $this->failure((string) Str::uuid()));
        Http::assertSentCount(1);
    }

    public function test_connection_failure_is_not_retried_and_starts_cooldown(): void
    {
        config(['feedback.customer_sync.live_enabled' => true]);
        Http::fake(['https://user.test/*' => Http::failedConnection()]);
        $this->assertStringContainsString('connection failed', $this->failure((string) Str::uuid()));
        $this->assertStringContainsString('cooling down', $this->failure((string) Str::uuid()));
        Sleep::assertSleptTimes(1);
    }
}
