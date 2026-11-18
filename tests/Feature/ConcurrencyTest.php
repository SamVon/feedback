<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\MySqlTestDatabase;

class ConcurrencyTest extends TestCase
{
    public function test_concurrent_retries_lost_receipt_and_backup_restore(): void
    {
        $root = dirname(__DIR__, 2);
        $database = new MySqlTestDatabase;
        $payloadFile = tempnam(sys_get_temp_dir(), 'eg-payload-');
        $env = $database->environment();
        $run = function (array $args) use ($root, $env) {
            $p = new Process([PHP_BINARY, ...$args], $root, $env);
            $p->setTimeout(45);
            $p->mustRun();

            return $p->getOutput();
        };
        $workers = [];
        try {
            $run(['artisan', 'migrate', '--force', '--no-interaction']);
            file_put_contents($payloadFile, $run(['tests/concurrent-worker.php', 'setup']));
            for ($i = 0; $i < 6; $i++) {
                $p = new Process([PHP_BINARY, 'tests/concurrent-worker.php', 'submit', $payloadFile, 'same-key'], $root, $env);
                $p->start();
                $workers[] = $p;
            }
            $statuses = [];
            $ids = [];
            foreach ($workers as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());
                $this->assertJson($p->getOutput());
                [$body,$status] = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $statuses[] = $status;
                $ids[] = $body['response_id'];
            }
            sort($statuses);
            $this->assertSame([200, 200, 200, 200, 200, 201], $statuses);
            $this->assertCount(1, array_unique($ids));
            $data = json_decode(file_get_contents($payloadFile), true);
            $data['client_submission_id'] = 'lost-receipt';
            file_put_contents($payloadFile, json_encode($data));
            $lost = new Process([PHP_BINARY, 'tests/concurrent-worker.php', 'lose-response', $payloadFile, 'lost-key'], $root, $env);
            $lost->run();
            $this->assertSame(75, $lost->getExitCode(), $lost->getErrorOutput());
            [, $status] = json_decode($run(['tests/concurrent-worker.php', 'submit', $payloadFile, 'lost-key']), true);
            $this->assertSame(200, $status);
            $this->assertSame('2', $run(['tests/concurrent-worker.php', 'count']));
            $backup = $database->restoreBackup();
            $restored = new Process([PHP_BINARY, 'tests/concurrent-worker.php', 'count'], $root, array_replace($env, ['DB_DATABASE' => $backup]));
            $restored->mustRun();
            $this->assertSame('2', $restored->getOutput());
            $replay = new Process([PHP_BINARY, 'tests/concurrent-worker.php', 'submit', $payloadFile, 'lost-key'], $root, array_replace($env, ['DB_DATABASE' => $backup]));
            $replay->mustRun();
            [, $status] = json_decode($replay->getOutput(), true);
            $this->assertSame(200, $status);
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
