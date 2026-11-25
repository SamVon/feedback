<?php

namespace App\Console\Commands;

use App\Application\CustomerSyncException;
use App\Application\SyncCustomers;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class SyncFeedbackCustomers extends Command
{
    protected $signature = 'feedback:sync-customers {--run= : UUID of a previous sync to resume} {--snapshot-file= : Immutable, complete User Service export JSON} {--check : Validate configuration without network requests or writes} {--allow-partial : Import readable profiles as an incomplete preview when source records cannot be decoded}';

    protected $description = 'Sync minimal customer profiles through the existing Cohort API.';

    public function handle(SyncCustomers $sync): int
    {
        $run = $this->option('run') ?: (string) Str::uuid();
        $this->info('Run ID: '.$run);
        $lock = null;
        $acquired = false;
        try {
            if ($this->option('check')) {
                $sync->check(! $this->option('snapshot-file'));
                $this->info('Customer sync configuration is valid. Connections and permissions have not been tested.');

                return self::SUCCESS;
            }
            $lock = Cache::lock('feedback:sync-customers', 3600);
            $acquired = $lock->get();
            if (! $acquired) {
                $this->error('Another customer sync is running.');

                return self::FAILURE;
            }
            if ($this->option('allow-partial')) {
                $this->warn('Partial preview enabled: missing accounts are reported and no snapshot will be marked complete from offset pagination.');
            }
            $result = $sync->run($run, $this->option('snapshot-file'), function (string $message): void {
                $this->line($message);
            }, (bool) $this->option('allow-partial'));
            $this->info('Snapshot '.$result['snapshot_id'].' contains '.$result['count'].' accounts.');
            if ($result['missing_count'] > 0) {
                $this->warn('Missing accounts: '.$result['missing_count'].' of '.$result['source_total'].'. Select Latest imported list (preview) in Customers to inspect readable profiles.');
            }
            if (! $result['complete']) {
                $this->warn('Snapshot remains incomplete: offset pagination cannot prove a consistent full export. Supply a verified immutable snapshot export.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $reason = match (true) {
                $exception instanceof CustomerSyncException => $exception->getMessage(),
                $exception instanceof ValidationException => 'Invalid fields: '.implode(', ', array_keys($exception->errors())),
                $exception instanceof JsonException => 'Invalid JSON in the snapshot export or sync journal.',
                $exception instanceof DecryptException => 'The saved journal cannot be decrypted. Restore the APP_KEY used by this run.',
                default => 'An internal or storage error occurred. Check database connectivity and private storage permissions.',
            };
            $this->error('Customer sync failed. '.$reason);
            $this->line('After recovery, resume with --run='.$run);

            return self::FAILURE;
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }
}
