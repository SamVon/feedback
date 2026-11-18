<?php

use App\Application\Invitations;
use App\Application\PopupPolicy;
use App\Application\SubmitFeedback;
use App\Models\FeedbackAnswer;
use App\Models\FeedbackResponse;
use App\Models\Question;
use App\Models\RuleVersion;
use App\Models\Survey;
use App\Models\SurveyVersion;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1];
if (in_array($mode, ['setup', 'setup-popup'], true)) {
    $survey = Survey::create(['code' => 'concurrency', 'name' => 'Concurrency fixture']);
    $version = SurveyVersion::create(['survey_id' => $survey->id, 'version' => 1, 'status' => 'draft']);
    $question = Question::create(['survey_version_id' => $version->id, 'code' => 'rating', 'type' => 'rating', 'label' => ['en' => 'Rating', 'lo' => 'Rating'], 'required' => true, 'position' => 0]);
    $version->update(['status' => 'published', 'published_at' => now()]);
    RuleVersion::create(['version' => 1, 'status' => 'published', 'published_at' => now(), 'rules' => ['channels' => ['always_on' => true, 'auto_prompt' => true], 'transaction_types' => ['BUY_GOLD'], 'cooldown_seconds' => 0, 'rate_limit' => 20, 'rate_window_seconds' => 60, 'invitation_ttl_seconds' => 3600]]);
    $payload = ['client_submission_id' => 'concurrent-sub', 'actor_user_id' => 'concurrent-actor', 'survey_version_id' => $version->id, 'source' => 'always_on', 'submitted_at' => now()->subSecond()->utc()->format('Y-m-d\TH:i:s\Z'), 'answers' => [['question_id' => $question->id, 'rating_value' => 5]]];
    if ($mode === 'setup-popup') {
        $present = Carbon::now();
        $issue = function (string $transaction) use ($version, $payload): array {
            [$invitation] = app(Invitations::class)->evaluate('concurrency', $transaction, ['actor_user_id' => $payload['actor_user_id'], 'survey_version_id' => $version->id, 'transaction_context' => ['transaction_id' => $transaction, 'transaction_type' => 'BUY_GOLD', 'transaction_occurred_at' => now()->subMinute()->format('Y-m-d\TH:i:s\Z'), 'verified_at' => now()->format('Y-m-d\TH:i:s\Z'), 'success_verified' => true]]);

            return array_replace($payload, ['source' => 'auto_prompt', 'invitation_id' => $invitation['invitation_id'], 'client_submission_id' => $transaction, 'submitted_at' => now()->format('Y-m-d\TH:i:s\Z')]);
        };
        $priorCount = (int) $argv[2];
        for ($i = 0; $i < $priorCount; $i++) {
            Carbon::setTestNow($present->copy()->subDays(31 * ($priorCount - $i)));
            app(SubmitFeedback::class)->execute('concurrency', 'history-'.$i, $issue('history-'.$i));
        }
        Carbon::setTestNow();
        $payload = [];
        for ($i = 0; $i < 6; $i++) {
            $payload[] = $issue('concurrent-popup-'.$i);
        }
    }
    echo json_encode($payload);
    exit;
}
if ($mode === 'count') {
    echo FeedbackResponse::count();
    exit;
}
if ($mode === 'popup-state') {
    echo json_encode(app(PopupPolicy::class)->state('concurrent-actor', 'BUY_GOLD', RuleVersion::firstOrFail()->rules) + ['answer_count' => FeedbackAnswer::count(), 'response_keys' => DB::table('idempotency_records')->where('operation', 'responses.create')->count()]);
    exit;
}
$payload = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$key = $argv[3];
if ($mode === 'submit-popup') {
    $payload = $payload[(int) $argv[4]];
    $startAt = (float) ($argv[5] ?? 0);
    while (microtime(true) < $startAt) {
        usleep(1000);
    }
}
try {
    $result = app(SubmitFeedback::class)->execute('concurrency', $key, $payload);
} catch (HttpExceptionInterface $exception) {
    if ($mode !== 'submit-popup') {
        throw $exception;
    }
    $result = [['message' => $exception->getMessage()], $exception->getStatusCode()];
}
if ($mode === 'lose-response') {
    exit(75);
} // Commit succeeded; transport never delivers a receipt.
echo json_encode($result);
