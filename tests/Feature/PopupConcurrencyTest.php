<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\MySqlTestDatabase;

class PopupConcurrencyTest extends TestCase
{
    public static function existingCounts(): array
    {
        return ['first response' => [0], 'last available response' => [2]];
    }

    #[DataProvider('existingCounts')]
    public function test_distinct_concurrent_submissions_cannot_bypass_cooldown_or_quota(int $priorCount): void
    {
        $root = dirname(__DIR__, 2);
        $database = new MySqlTestDatabase;
        $payloadFile = tempnam(sys_get_temp_dir(), 'eg-popup-payload-');
        $env = $database->environment();
        $run = function (array $args) use ($root, $env): string {
            $process = new Process([PHP_BINARY, ...$args], $root, $env);
            $process->setTimeout(45);
            $process->mustRun();

            return $process->getOutput();
        };
        $workers = [];
        try {
            $run(['artisan', 'migrate', '--force', '--no-interaction']);
            file_put_contents($payloadFile, $run(['tests/concurrent-worker.php', 'setup-popup', (string) $priorCount]));
            $startAt = (string) (microtime(true) + 2);
            for ($index = 0; $index < 6; $index++) {
                $worker = new Process([PHP_BINARY, 'tests/concurrent-worker.php', 'submit-popup', $payloadFile, 'distinct-key-'.$index, (string) $index, $startAt], $root, $env);
                $worker->setTimeout(45);
                $worker->start();
                $workers[] = $worker;
            }
            $statuses = [];
            $winner = null;
            $receipt = null;
            foreach ($workers as $index => $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $this->assertJson($worker->getOutput());
                [$body, $status] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $statuses[] = $status;
                if ($status === 201) {
                    $winner = $index;
                    $receipt = $body;
                } else {
                    $this->assertSame('Pop-up submission denied: '.($priorCount === 2 ? 'response_limit_reached' : 'cooldown_active'), $body['message']);
                }
            }
            sort($statuses);
            $this->assertSame([201, 422, 422, 422, 422, 422], $statuses);
            $state = json_decode($run(['tests/concurrent-worker.php', 'popup-state']), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($priorCount + 1, $state['successful_response_count']);
            $this->assertSame($priorCount + 1, $state['answer_count']);
            $this->assertSame($priorCount + 1, $state['response_keys']);
            $this->assertFalse($state['eligible']);
            $this->assertSame((new \DateTimeImmutable($receipt['received_at']))->modify('+30 days')->format('Y-m-d\TH:i:s\Z'), $state['cooldown_until']);
            [$replay, $status] = json_decode($run(['tests/concurrent-worker.php', 'submit-popup', $payloadFile, 'distinct-key-'.$winner, (string) $winner]), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(200, $status);
            $this->assertEquals($receipt, $replay);
            $this->assertSame($state, json_decode($run(['tests/concurrent-worker.php', 'popup-state']), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $database->cleanup();
            foreach ([$payloadFile] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
