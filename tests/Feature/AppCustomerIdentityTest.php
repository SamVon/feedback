<?php

namespace Tests\Feature;

use App\Application\Cohorts;
use App\Application\CustomerRosterExport;
use App\Application\Customers as CustomerService;
use App\Filament\Pages\CustomerDetail;
use App\Filament\Pages\Customers;
use App\Filament\Resources\Pages\ListFeedback;
use App\Models\CohortMember;
use App\Models\FeedbackResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;

class AppCustomerIdentityTest extends FeedbackTest
{
    private array $identityClaims = [];

    private function customerToken(string $actor = 'customer-1'): string
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $claims = $encode(array_merge($this->identityClaims, ['_id' => $actor, 'role' => 'CUSTOMER', 'exp' => time() + 3600]));

        return $header.'.'.$claims.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$claims, 'test-customer-secret', true)), '+/', '-_'), '=');
    }

    private function appPost(array $payload, string $key, string $actor = 'customer-1'): TestResponse
    {
        return $this->withToken($this->customerToken($actor))->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/feedback/responses', $payload);
    }

    public function test_app_identity_is_encrypted_bound_to_jwt_and_hidden_from_receipts(): void
    {
        $payload = $this->payload('identity-1', 'forged-customer');
        $this->identityClaims = ['first_name' => 'App', 'last_name' => 'customer', 'phone' => '02000123456'];

        $receipt = $this->appPost($payload, 'identity-1')->assertCreated()
            ->assertJsonMissingPath('name')->assertJsonMissingPath('phone')->assertJsonMissingPath('customer_name')->json();

        $response = FeedbackResponse::findOrFail($receipt['response_id']);
        $this->assertSame('customer-1', $response->actor_user_id);
        $this->assertSame('App customer', $response->customer_name);
        $this->assertSame('02000123456', $response->customer_phone);
        $raw = DB::table('feedback_responses')->where('id', $response->id)->first();
        $this->assertNotSame('App customer', $raw->customer_name);
        $this->assertNotSame('02000123456', $raw->customer_phone);
        $this->assertArrayNotHasKey('customer_name', $response->toArray());
        $this->assertArrayNotHasKey('customer_phone', $response->toArray());
        $this->assertDatabaseMissing('feedback_responses', ['actor_user_id' => 'forged-customer']);
        $this->assertDatabaseCount('cohort_members', 0);
    }

    public function test_app_identity_popup_submission_requires_owned_invitation(): void
    {
        $transaction = ['survey_version_id' => $this->survey->id, 'transaction_id' => 'identity-buy', 'transaction_type' => 'BUY_GOLD',
            'transaction_occurred_at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z')];
        $invitation = $this->withToken($this->customerToken())->withHeader('Idempotency-Key', 'identity-eligibility')
            ->postJson('/api/v1/feedback/eligibility', $transaction)->assertCreated()->json('invitation_id');
        $payload = $this->payload('identity-popup');
        $payload['source'] = 'auto_prompt';
        $payload['invitation_id'] = $invitation;
        $this->identityClaims = ['first_name' => 'Popup', 'last_name' => 'customer', 'phone' => '+856 20 0012 3456'];

        $this->appPost($payload, 'identity-popup-other', 'customer-2')->assertUnprocessable();
        $this->assertDatabaseCount('feedback_responses', 0);
        $this->appPost($payload, 'identity-popup')->assertCreated();

        $stored = FeedbackResponse::firstOrFail();
        $this->assertSame('Popup customer', $stored->customer_name);
        $this->assertSame('+856 20 0012 3456', $stored->customer_phone);
        $this->assertSame('customer-1', $stored->actor_user_id);
    }

    #[DataProvider('invalidIdentity')]
    public function test_app_identity_rejects_invalid_fields_without_writing_a_response(string $field, mixed $value): void
    {
        $payload = $this->payload('invalid-identity');
        $payload[$field] = $value;

        $this->appPost($payload, 'invalid-identity')->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('feedback_responses', 0);
        $this->assertDatabaseMissing('idempotency_records', ['operation' => 'responses.create']);
    }

    public static function invalidIdentity(): array
    {
        return [
            'name is no longer accepted in body' => ['name', 'Body name'],
            'phone is no longer accepted in body' => ['phone', '02000123456'],
            'name too long' => ['name', str_repeat('a', 201)],
            'name object' => ['name', ['actor_user_id' => 'another-customer']],
            'phone number loses leading zero' => ['phone', 2000123456],
            'phone text' => ['phone', 'not-a-phone'],
            'phone too long' => ['phone', str_repeat('2', 41)],
        ];
    }

    public function test_app_identity_ignores_body_profile_objects_and_handles_missing_or_invalid_token_profile(): void
    {
        $payload = $this->payload('no-token-profile');
        $payload['customer_profile'] = ['name' => 'Forged name', 'phone' => '02000999999'];
        $this->identityClaims = ['first_name' => ['invalid'], 'last_name' => str_repeat('a', 201), 'phone' => ['invalid']];
        $id = $this->appPost($payload, 'no-token-profile')->assertCreated()->json('response_id');
        $response = FeedbackResponse::findOrFail($id);
        $this->assertSame('customer-1', $response->actor_user_id);
        $this->assertNull($response->customer_name);
        $this->assertNull($response->customer_phone);
        $this->identityClaims = [];
        $id = $this->appPost($this->payload('missing-token-profile'), 'missing-token-profile')->assertCreated()->json('response_id');
        $this->assertNull(FeedbackResponse::findOrFail($id)->customer_name);
        $this->assertNull(FeedbackResponse::findOrFail($id)->customer_phone);
    }

    public function test_app_identity_service_submission_also_rejects_profile_body_fields(): void
    {
        $payload = array_replace($this->payload('internal-profile'), ['name' => 'Body name', 'phone' => '02000123456']);
        $this->api($payload, 'internal-profile')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['name', 'phone']]]);
        $this->assertDatabaseCount('feedback_responses', 0);
    }

    public function test_app_identity_retry_cannot_change_profile_and_omitted_fields_preserve_last_known_values(): void
    {
        $this->freezeTime();
        $payload = $this->payload('first-profile');
        $this->identityClaims = ['first_name' => 'First', 'last_name' => 'profile', 'phone' => '02000111111'];
        $receipt = $this->appPost($payload, 'first-profile')->assertCreated()->json();
        $this->appPost($payload, 'first-profile')->assertOk()->assertExactJson($receipt);
        $this->identityClaims = ['first_name' => 'Rotated token profile', 'phone' => '02000999999'];
        $this->appPost($payload, 'first-profile')->assertOk()->assertExactJson($receipt);
        $changed = array_replace($payload, ['name' => 'Conflicting profile']);
        $this->appPost($changed, 'first-profile')->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->appPost($changed, 'different-key')->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->travel(1)->second();
        $second = $this->payload('second-profile');
        $this->identityClaims = ['first_name' => 'Second', 'last_name' => 'profile'];
        $this->appPost($second, 'second-profile')->assertCreated();
        $this->travel(1)->second();
        $this->identityClaims = [];
        $this->appPost($this->payload('old-app-without-profile'), 'old-app-without-profile')->assertCreated();
        $this->appPost($this->payload('token-without-profile'), 'token-without-profile')->assertCreated();

        $row = app(CustomerService::class)->listing($this->staff, ['from' => now()->addDay()->toDateString()])->firstOrFail();

        $this->assertSame('Second profile', $row->display_name);
        $this->assertSame('02000111111', $row->phone);
        $this->assertSame(0, (int) $row->response_count);
        $this->assertSame('First profile', FeedbackResponse::findOrFail($receipt['response_id'])->customer_name);
        $this->assertDatabaseCount('feedback_responses', 4);
        $this->assertDatabaseCount('cohort_members', 0);
    }

    public function test_app_identity_staff_pages_escape_and_export_body_profile_and_enforce_permissions(): void
    {
        $payload = $this->payload('body-profile');
        $name = '<script>client-profile</script>';
        $phone = '02000123456';
        $this->identityClaims = ['first_name' => $name, 'phone' => $phone];
        $this->appPost($payload, 'body-profile')->assertCreated();
        $query = app(CustomerService::class)->listing($this->staff, []);
        $member = (clone $query)->firstOrFail();
        $this->assertTrue($member->app_reported_name);
        $this->assertTrue($member->app_reported_phone);
        $this->assertFalse($member->identity_complete);
        $this->actingAs($this->staff);
        Livewire::test(Customers::class)->assertSee($name)->assertSee($phone)
            ->assertSee(__('feedback.customer_app_reported'))->assertDontSee('<script>client-profile</script>', false);
        Livewire::test(ListFeedback::class)->assertSee('&lt;script&gt;client-profile', false)->assertSee($phone)
            ->assertDontSee('<script>client-profile</script>', false);
        $url = CustomerDetail::getUrl(['record' => $member->id]);
        $this->get($url)->assertOk()->assertSee($name)->assertSee($phone)
            ->assertSee(__('feedback.customer_app_reported_profile'))->assertDontSee('<script>client-profile</script>', false);
        $export = app(CustomerRosterExport::class)->download($this->staff, $query, 'xlsx');
        ob_start();
        $export->sendContent();
        $bytes = ob_get_clean();
        $file = tempnam(sys_get_temp_dir(), 'body-profile-export-');
        try {
            file_put_contents($file, $bytes);
            $book = IOFactory::load($file);
            $this->assertSame($name, $book->getActiveSheet()->getCell('B2')->getValue());
            $this->assertSame($phone, $book->getActiveSheet()->getCell('C2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }

        $viewer = User::factory()->create(['active' => true, 'permissions' => ['customers.read', 'feedback.read']]);
        $this->actingAs($viewer)->get($url)->assertOk()->assertDontSee('client-profile')->assertDontSee($phone);
        Livewire::test(Customers::class)->assertDontSee('client-profile')->assertDontSee($phone);
        Livewire::test(ListFeedback::class)->assertDontSee('client-profile')->assertDontSee($phone);
        $limited = app(CustomerService::class)->listing($viewer, [])->firstOrFail();
        $this->assertArrayNotHasKey('display_name', $limited->getAttributes());
        $this->assertArrayNotHasKey('phone', $limited->getAttributes());
    }

    public function test_app_identity_prefers_token_profile_without_overwriting_synced_member(): void
    {
        $cohorts = app(Cohorts::class);
        [$snapshot] = $cohorts->start('user-adapter', 'synced-start', [
            'external_id' => 'synced-profile', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expected_count' => 1, 'expected_batches' => 1, 'identity_type' => 'account',
        ]);
        $cohorts->members('user-adapter', 'synced-member', $snapshot['snapshot_id'], ['batch_id' => 'batch-1', 'members' => [
            ['actor_user_id' => 'customer-1', 'display_name' => 'Synced profile', 'identity_complete' => true],
        ]]);
        $cohorts->complete('user-adapter', 'synced-complete', $snapshot['snapshot_id']);
        $payload = $this->payload('profile-fill');
        $this->identityClaims = ['first_name' => 'App supplied profile', 'phone' => '02000123456'];

        $this->appPost($payload, 'profile-fill')->assertCreated();

        $row = app(CustomerService::class)->listing($this->staff, [])->firstOrFail();
        $this->assertSame('App supplied profile', $row->display_name);
        $this->assertSame('02000123456', $row->phone);
        $this->assertTrue($row->app_reported_name);
        $this->assertTrue($row->app_reported_phone);
        $this->assertSame('Synced profile', CohortMember::firstOrFail()->display_name);
        $this->assertNull(CohortMember::firstOrFail()->phone);
        $detail = app(CustomerService::class)->member($this->staff, $row->id);
        $this->assertSame('App supplied profile', $detail->display_name);
        $this->assertSame('02000123456', $detail->phone);
        $this->actingAs($this->staff)->get(CustomerDetail::getUrl(['record' => $row->id]))
            ->assertOk()->assertSee('App supplied profile')->assertSee('02000123456');
        Livewire::test(Customers::class)->assertSee('App supplied profile')->assertSee('02000123456')->assertDontSee('Synced profile');
        Livewire::test(ListFeedback::class)->assertSee('App supplied profile')->assertSee('02000123456');
    }
}
