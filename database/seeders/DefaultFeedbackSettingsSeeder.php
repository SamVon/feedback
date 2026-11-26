<?php

namespace Database\Seeders;

use App\Application\Audit;
use App\Application\Idempotency;
use App\Application\PopupPolicy;
use App\Models\DailyFeedbackLimit;
use App\Models\RuleVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultFeedbackSettingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            Idempotency::lockActor('__configuration__');

            if (! RuleVersion::exists()) {
                $rules = PopupPolicy::normalizeRules([
                    'channels' => ['auto_prompt' => true, 'always_on' => true],
                    'transaction_types' => PopupPolicy::CUSTOMER_TRANSACTION_TYPES,
                    'rate_limit' => 5,
                    'rate_window_seconds' => 3600,
                    'invitation_ttl_seconds' => 86400,
                ]);
                $version = RuleVersion::create([
                    'version' => 1,
                    'revision' => 1,
                    'status' => 'published',
                    'rules' => $rules,
                    'published_at' => now(),
                ]);
                Audit::record('system:default-settings', 'rules.seed', 'rules', $version->id, ['after' => $rules]);
            }

            if (! DailyFeedbackLimit::exists()) {
                $limit = DailyFeedbackLimit::create(['response_limit' => DailyFeedbackLimit::DEFAULT_LIMIT, 'editor_id' => null]);
                Audit::record('system:default-settings', 'rules.daily_limit', 'daily_feedback_limit', (string) $limit->id, [
                    'before' => null,
                    'after' => $limit->response_limit,
                ]);
            }
        }, 5);
    }
}
