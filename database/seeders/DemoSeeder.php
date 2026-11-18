<?php

namespace Database\Seeders;

use App\Application\Changes;
use App\Application\Cohorts;
use App\Application\PopupPolicy;
use App\Models\FeedbackAnswer;
use App\Models\FeedbackResponse;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo data is local/testing only.');
        }
        if (Survey::where('code', 'demo-experience')->exists()) {
            $this->command?->info('DEMO data already exists.');

            return;
        }
        DB::transaction(function () {
            $operator = User::create(['name' => 'DEMO seed operator — disabled', 'email' => 'demo-seeder@example.invalid', 'password' => Str::random(64), 'active' => true, 'permissions' => config('feedback.permissions')]);
            $changes = app(Changes::class);
            $version = $changes->surveyDraft($operator, ['code' => 'demo-experience', 'name' => 'DEMO · Easy Gold Experience', 'questions' => [
                ['code' => 'overall', 'type' => 'rating', 'label' => ['en' => 'How was your Easy Gold experience?', 'lo' => 'ທ່ານພໍໃຈກັບ Easy Gold ຫຼາຍປານໃດ?'], 'required' => true, 'max_length' => 2000, 'max_choices' => 1, 'options' => []],
                ['code' => 'improve', 'type' => 'choice', 'label' => ['en' => 'What could we improve?', 'lo' => 'ພວກເຮົາຄວນປັບປຸງຫຍັງ?'], 'required' => false, 'max_length' => 2000, 'max_choices' => 2, 'options' => [
                    ['code' => 'speed', 'label' => ['en' => 'Transaction speed', 'lo' => 'ຄວາມໄວ']], ['code' => 'clarity', 'label' => ['en' => 'Clear information', 'lo' => 'ຂໍ້ມູນຊັດເຈນ']], ['code' => 'support', 'label' => ['en' => 'Customer support', 'lo' => 'ການຊ່ວຍເຫຼືອ']],
                ]],
                ['code' => 'comment', 'type' => 'text', 'label' => ['en' => 'Tell us more', 'lo' => 'ຄຳແນະນຳເພີ່ມເຕີມ'], 'required' => false, 'max_length' => 2000, 'max_choices' => 1, 'options' => []],
            ]]);
            $rule = $changes->ruleDraft($operator, ['channels' => ['auto_prompt' => true, 'always_on' => true], 'transaction_rules' => PopupPolicy::normalizeRules(['transaction_types' => ['BUY_GOLD', 'SALE_GOLD', 'WITHDRAW_GOLD']])['transaction_rules'], 'rate_limit' => 5, 'rate_window_seconds' => 3600, 'invitation_ttl_seconds' => 86400]);
            foreach ([['survey', $version], ['rules', $rule]] as [$type,$model]) {
                foreach (['submit', 'approve', 'publish'] as $action) {
                    $changes->transition($operator, $type, $model->id, 1, $action);
                }
            }
            $rating = $version->questions()->where('type', 'rating')->first();
            $choice = $version->questions()->where('type', 'choice')->first();
            $comment = $version->questions()->where('type', 'text')->first();
            $options = $choice->options()->pluck('id');
            $scores = array_merge(array_fill(0, 1000, 5), array_fill(0, 275, 4), array_fill(0, 210, 2), array_fill(0, 15, 1));
            // Deterministic synthetic acceptance fixture: 1,500 answers, sum 6,535, 1,275 satisfied.
            foreach ($scores as $i => $score) {
                $time = now()->subDays($i % 30)->subMinutes($i % 60);
                $r = FeedbackResponse::create(['caller' => 'demo', 'client_submission_id' => 'demo-'.$i, 'payload_hash' => hash('sha256', 'demo-'.$i), 'actor_user_id' => 'demo-account-'.str_pad((string) ($i % 220 + 1), 3, '0', STR_PAD_LEFT), 'survey_version_id' => $version->id, 'source' => 'always_on', 'submitted_at' => $time->copy()->subSeconds(2), 'received_at' => $time, 'app_version' => '1.0.demo', 'os' => $i % 2 ? 'ios' : 'android']);
                FeedbackAnswer::create(['feedback_response_id' => $r->id, 'question_id' => $rating->id, 'rating_value' => $score]);
                if ($i % 3 === 0) {
                    $answer = FeedbackAnswer::create(['feedback_response_id' => $r->id, 'question_id' => $choice->id]);
                    $answer->options()->attach($options[$i % count($options)]);
                }
                if ($i % 25 === 0) {
                    FeedbackAnswer::create(['feedback_response_id' => $r->id, 'question_id' => $comment->id, 'text_value' => 'DEMO · '.($score >= 4 ? 'Easy to use. Thank you!' : 'Please make the transaction status clearer.')]);
                }
            }
            $cohorts = app(Cohorts::class);
            [$snapshot] = $cohorts->start('demo', 'demo-snapshot', ['external_id' => 'demo-customers', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expected_count' => 260, 'expected_batches' => 1, 'identity_type' => 'account']);
            $members = [];
            for ($i = 1; $i <= 260; $i++) {
                $members[] = ['actor_user_id' => 'demo-account-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'display_name' => 'DEMO Customer '.$i, 'phone' => null, 'identity_complete' => $i !== 260];
            }
            $cohorts->members('demo', 'demo-members', $snapshot['snapshot_id'], ['batch_id' => '1', 'members' => $members]);
            $cohorts->complete('demo', 'demo-complete', $snapshot['snapshot_id']);
            $operator->update(['active' => false, 'permissions' => []]);
        });
    }
}
