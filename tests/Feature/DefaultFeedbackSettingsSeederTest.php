<?php

namespace Tests\Feature;

use App\Application\PopupPolicy;
use App\Models\AuditEvent;
use App\Models\DailyFeedbackLimit;
use App\Models\RuleVersion;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultFeedbackSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultFeedbackSettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seed_creates_published_popup_defaults_and_daily_feedback_limit_without_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $version = RuleVersion::sole();
        $this->assertSame('published', $version->status);
        $this->assertNotNull($version->published_at);
        $this->assertEquals(['auto_prompt' => true, 'always_on' => true], $version->rules['channels']);
        $this->assertSame(PopupPolicy::CUSTOMER_TRANSACTION_TYPES, array_column($version->rules['transaction_rules'], 'transaction_type'));
        foreach ($version->rules['transaction_rules'] as $rule) {
            $this->assertTrue($rule['enabled']);
            $this->assertSame(3, $rule['max_responses']);
            $this->assertSame(30, $rule['cooldown_days']);
        }
        $this->assertEquals(['starts_at' => null, 'ends_at' => null], $version->rules['popup_schedule']);
        $this->assertSame(3, DailyFeedbackLimit::current()->response_limit);
        $this->assertNull(DailyFeedbackLimit::current()->editor_id);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('surveys', 0);
        $this->assertDatabaseCount('feedback_responses', 0);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_repeated_seed_does_not_duplicate_or_modify_existing_settings_or_audit_events(): void
    {
        $this->seed(DefaultFeedbackSettingsSeeder::class);
        $version = RuleVersion::sole()->getAttributes();
        $limit = DailyFeedbackLimit::current()->getAttributes();
        $audits = AuditEvent::orderBy('id')->get()->toArray();
        $this->travel(1)->day();

        $this->seed(DefaultFeedbackSettingsSeeder::class);

        $this->assertDatabaseCount('rule_versions', 1);
        $this->assertDatabaseCount('daily_feedback_limits', 1);
        $this->assertSame($version, RuleVersion::sole()->getAttributes());
        $this->assertSame($limit, DailyFeedbackLimit::current()->getAttributes());
        $this->assertSame($audits, AuditEvent::orderBy('id')->get()->toArray());
    }

    public function test_existing_custom_popup_rules_are_preserved_while_missing_daily_limit_is_initialized(): void
    {
        $version = RuleVersion::create([
            'version' => 7, 'revision' => 2, 'status' => 'draft',
            'rules' => ['channels' => ['auto_prompt' => false, 'always_on' => false]],
        ]);
        $original = $version->fresh()->getAttributes();

        $this->seed(DefaultFeedbackSettingsSeeder::class);

        $this->assertDatabaseCount('rule_versions', 1);
        $this->assertSame($original, $version->fresh()->getAttributes());
        $this->assertSame(3, DailyFeedbackLimit::current()->response_limit);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_existing_daily_limit_is_preserved_while_missing_popup_rules_are_initialized(): void
    {
        $limit = DailyFeedbackLimit::create(['response_limit' => 8]);
        $original = $limit->fresh()->getAttributes();

        $this->seed(DefaultFeedbackSettingsSeeder::class);

        $this->assertDatabaseCount('daily_feedback_limits', 1);
        $this->assertSame($original, $limit->fresh()->getAttributes());
        $this->assertSame('published', RuleVersion::sole()->status);
        $this->assertDatabaseCount('audit_events', 1);
    }
}
