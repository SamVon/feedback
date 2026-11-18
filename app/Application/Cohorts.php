<?php

namespace App\Application;

use App\Models\CohortBatch;
use App\Models\CohortMember;
use App\Models\CohortSnapshot;
use Illuminate\Support\Facades\Validator;

final class Cohorts
{
    public function start(string $caller, string $key, array $data): array
    {
        $data = Validator::make($data, ['external_id' => 'required|string|max:100', 'captured_at' => 'required|date_format:Y-m-d\TH:i:s\Z|before_or_equal:now', 'expected_count' => 'required|integer|min:0|max:10000000', 'expected_batches' => 'required|integer|min:0|max:100000', 'identity_type' => 'required|in:account'])->validate();

        return app(Idempotency::class)->run($caller, 'cohorts.start', $key, $data, function () use ($caller, $data) {
            Idempotency::lockActor('__snapshot:'.hash('sha256', $caller.':'.$data['external_id']));
            $snapshot = CohortSnapshot::where('caller', $caller)->where('external_id', $data['external_id'])->first();
            if ($snapshot) {
                foreach (['expected_count', 'expected_batches', 'identity_type'] as $field) {
                    abort_unless((string) $snapshot->$field === (string) $data[$field], 409, 'Snapshot conflict.');
                } abort_unless($snapshot->captured_at->equalTo($data['captured_at']), 409, 'Snapshot time conflict.');

                return [['snapshot_id' => $snapshot->id, 'status' => $snapshot->status], 200];
            }
            $snapshot = CohortSnapshot::create($data + ['caller' => $caller]);

            return [['snapshot_id' => $snapshot->id, 'status' => 'incomplete'], 201];
        });
    }

    public function members(string $caller, string $key, string $id, array $data): array
    {
        $data = Validator::make($data, ['batch_id' => 'required|string|max:100', 'members' => 'required|array|min:1|max:500', 'members.*' => 'array:actor_user_id,one_id,display_name,phone,identity_complete', 'members.*.actor_user_id' => 'required|string|max:100|distinct', 'members.*.one_id' => 'nullable|string|max:100', 'members.*.display_name' => 'nullable|string|max:200', 'members.*.phone' => 'nullable|string|max:40', 'members.*.identity_complete' => 'required|boolean'])->validate();

        return app(Idempotency::class)->run($caller, 'cohorts.members.'.$id, $key, $data, function () use ($caller, $id, $data) {
            $snapshot = CohortSnapshot::where('caller', $caller)->lockForUpdate()->findOrFail($id);
            $batch = CohortBatch::where('cohort_snapshot_id', $id)->where('batch_id', $data['batch_id'])->first();
            if ($batch) {
                abort_unless($batch->payload_hash === Idempotency::hash($data), 409, 'Batch conflict.');

                return [['snapshot_id' => $id, 'batch_id' => $data['batch_id']], 200];
            }
            abort_unless($snapshot->status === 'incomplete', 409, 'Snapshot is immutable.');
            abort_if(CohortBatch::where('cohort_snapshot_id', $id)->count() >= $snapshot->expected_batches || $snapshot->members()->count() + count($data['members']) > $snapshot->expected_count, 422, 'Snapshot exceeds manifest.');
            abort_if($snapshot->members()->whereIn('actor_user_id', array_column($data['members'], 'actor_user_id'))->exists(), 409, 'Customer repeated across batches.');
            foreach ($data['members'] as $member) {
                CohortMember::create($member + ['cohort_snapshot_id' => $id]);
            }
            CohortBatch::create(['cohort_snapshot_id' => $id, 'batch_id' => $data['batch_id'], 'payload_hash' => Idempotency::hash($data)]);

            return [['snapshot_id' => $id, 'batch_id' => $data['batch_id']], 201];
        });
    }

    public function complete(string $caller, string $key, string $id): array
    {
        return app(Idempotency::class)->run($caller, 'cohorts.complete.'.$id, $key, [], function () use ($caller, $id) {
            $snapshot = CohortSnapshot::where('caller', $caller)->lockForUpdate()->findOrFail($id);
            abort_unless($snapshot->members()->count() === $snapshot->expected_count && CohortBatch::where('cohort_snapshot_id', $id)->count() === $snapshot->expected_batches, 422, 'Snapshot manifest incomplete.');
            if ($snapshot->status !== 'complete') {
                $snapshot->update(['status' => 'complete', 'completed_at' => now()]);
                Audit::record($caller, 'cohort.complete', 'cohort', $id, ['count' => $snapshot->expected_count]);
            }

            return [['snapshot_id' => $id, 'status' => 'complete'], 200];
        });
    }
}
