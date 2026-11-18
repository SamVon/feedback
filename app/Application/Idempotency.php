<?php

namespace App\Application;

use App\Models\IdempotencyRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Idempotency
{
    public static function hash(array $data): string
    {
        $sort = function (&$value) use (&$sort) {
            if (! is_array($value)) {
                return;
            } if (! array_is_list($value)) {
                ksort($value);
            } foreach ($value as &$child) {
                $sort($child);
            }
        };
        $sort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function run(string $caller, string $operation, string $key, array $payload, callable $work): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422, 'A valid Idempotency-Key is required.');

        return DB::transaction(function () use ($caller, $operation, $key, $payload, $work) {
            $scope = compact('caller', 'operation', 'key');
            $hash = self::hash($payload);
            // MySQL duplicate-key updates take a write lock immediately, avoiding shared-lock upgrades.
            // Update only the identical key; never overwrite the original hash or receipt on retry.
            DB::table('idempotency_records')->upsert(
                [$scope + ['id' => (string) Str::uuid(), 'payload_hash' => $hash, 'created_at' => now()]],
                ['caller', 'operation', 'key'],
                ['key'],
            );
            $record = IdempotencyRecord::where($scope)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($record->payload_hash, $hash), 409, 'Idempotency key reused with different payload.');
            if ($record->response !== null) {
                return [$record->response, 200];
            }
            [$body, $status] = $work();
            $record->update(['response' => $body]);

            return [$body, $status];
        }, 5);
    }

    public static function lockActor(string $actor): void
    {
        DB::table('actor_locks')->upsert([['actor_user_id' => $actor]], ['actor_user_id'], ['actor_user_id']);
        DB::table('actor_locks')->where('actor_user_id', $actor)->lockForUpdate()->first();
    }
}
