<?php

namespace App\Application;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class SyncCustomers
{
    public function run(string $run, ?string $exportFile = null, ?Closure $progress = null, bool $allowPartial = false): array
    {
        Validator::make(['run' => $run], ['run' => 'required|uuid'])->validate();
        $disk = Storage::disk('local');
        $journal = 'customer-sync/'.$run.'.enc';
        $settings = $this->check(! $exportFile && ! $disk->exists($journal));
        $environment = $settings['environment'];
        if ($disk->exists($journal)) {
            $data = json_decode(Crypt::decryptString($disk->get($journal)), true, flags: JSON_THROW_ON_ERROR);
            if ($data['environment'] !== $environment || $data['feedback_url'] !== $settings['feedback_url'] || $data['user_url'] !== $settings['user_url']) {
                throw new CustomerSyncException('Resume must use the original source and target environment.');
            }
        } else {
            $data = $exportFile ? $this->export($exportFile, $environment) : $this->readUsers($settings, $progress, $allowPartial, $run);
            $data += ['environment' => $environment, 'feedback_url' => $settings['feedback_url'], 'user_url' => $settings['user_url']];
            $data['member_count'] = count($data['members']);
            $data['batch_count'] = (int) ceil($data['member_count'] / 500);
            $data['journal_format'] = 2;
            for ($index = 0; $index < $data['batch_count']; $index++) {
                $members = array_slice($data['members'], $index * 500, 500);
                if (! $disk->put('customer-sync/'.$run.'/batch-'.$index.'.enc', Crypt::encryptString(json_encode($members, JSON_THROW_ON_ERROR)))) {
                    throw new CustomerSyncException('Could not persist a customer sync batch.');
                }
            }
            unset($data['members'], $members);
            if (! $disk->put($journal, Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)))) {
                throw new CustomerSyncException('Could not persist the customer sync journal.');
            }
            $disk->deleteDirectory('customer-sync/'.$run.'/reading');
        }
        $count = $data['member_count'] ?? count($data['members']);
        $batchCount = $data['batch_count'] ?? (int) ceil($count / 500);
        $snapshot = $this->post($settings, 'cohort-snapshots', $run.'-start', [
            'external_id' => $run, 'captured_at' => $data['captured_at'],
            'expected_count' => $count, 'expected_batches' => $batchCount, 'identity_type' => 'account',
        ]);
        $id = $snapshot['snapshot_id'];
        for ($index = 0; $index < $batchCount; $index++) {
            $members = ($data['journal_format'] ?? 1) === 2
                ? json_decode(Crypt::decryptString($disk->get('customer-sync/'.$run.'/batch-'.$index.'.enc')), true, flags: JSON_THROW_ON_ERROR)
                : array_slice($data['members'], $index * 500, 500);
            $this->post($settings, "cohort-snapshots/{$id}/members", $run.'-batch-'.$index, ['batch_id' => 'batch-'.$index, 'members' => $members]);
            if (($index + 1) % 10 === 0 || $index === $batchCount - 1) {
                $progress?->__invoke('Imported batches: '.($index + 1).'/'.$batchCount);
            }
        }
        if ($data['verified']) {
            $this->post($settings, "cohort-snapshots/{$id}/complete", $run.'-complete', []);
        }

        return ['snapshot_id' => $id, 'count' => $count, 'complete' => $data['verified'], 'source_total' => $data['source_total'] ?? $count, 'missing_count' => $data['missing_count'] ?? 0];
    }

    public function check(bool $needsUserService = true): array
    {
        $settings = config('feedback.customer_sync');
        $environment = $settings['environment'];
        $required = [
            'environment' => 'FEEDBACK_CUSTOMER_SOURCE_ENVIRONMENT',
            'user_environment' => 'USER_SERVICE_ENVIRONMENT',
            'feedback_url' => 'FEEDBACK_SYNC_BASE_URL',
            'cohort_token' => 'FEEDBACK_COHORT_TOKEN',
        ];
        if ($needsUserService) {
            $required += ['user_url' => 'USER_SERVICE_BASE_URL', 'user_token' => 'USER_SERVICE_READ_TOKEN'];
        }
        $missing = array_values(array_filter($required, fn (string $variable, string $field): bool => blank($settings[$field] ?? null), ARRAY_FILTER_USE_BOTH));
        if ($missing) {
            throw new CustomerSyncException('Missing configuration: '.implode(', ', $missing));
        }
        if (! $environment || $environment !== $settings['user_environment']) {
            throw new CustomerSyncException('Set matching FEEDBACK_CUSTOMER_SOURCE_ENVIRONMENT and USER_SERVICE_ENVIRONMENT values.');
        }
        foreach (array_filter([$settings['feedback_url'], $settings['user_url']]) as $url) {
            $parts = parse_url($url);
            $scheme = $parts['scheme'] ?? '';
            $host = $parts['host'] ?? '';
            if (! $host || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || ($scheme !== 'https' && ! ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)))) {
                throw new CustomerSyncException('Sync URLs require HTTPS or loopback HTTP. Credentials belong in configuration.');
            }
        }

        return $settings;
    }

    private function readUsers(array $settings, ?Closure $progress, bool $allowPartial, string $run): array
    {
        if (blank($settings['user_url']) || blank($settings['user_token'])) {
            throw new CustomerSyncException('Set USER_SERVICE_BASE_URL and USER_SERVICE_READ_TOKEN.');
        }
        $captured = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $members = [];
        $total = null;
        $recoveries = 0;
        $firstPass = 0;
        $firstOffset = 0;
        $fileCount = 0;
        $disk = Storage::disk('local');
        $checkpoint = 'customer-sync/'.$run.'/reading';
        if ($disk->exists($checkpoint.'/state.enc')) {
            $state = json_decode(Crypt::decryptString($disk->get($checkpoint.'/state.enc')), true, flags: JSON_THROW_ON_ERROR);
            foreach (['environment', 'user_url', 'feedback_url'] as $field) {
                if ($state[$field] !== $settings[$field]) {
                    throw new CustomerSyncException('Resume must use the original source and target environment.');
                }
            }
            if ($state['allow_partial'] !== $allowPartial) {
                throw new CustomerSyncException('Resume must use the original --allow-partial setting.');
            }
            $captured = $state['captured_at'];
            $total = $state['total'];
            $recoveries = $state['recoveries'];
            $firstPass = $state['pass'];
            $firstOffset = $state['offset'];
            $fileCount = $state['file_count'];
            for ($index = 0; $index < $fileCount; $index++) {
                $page = json_decode(Crypt::decryptString($disk->get($checkpoint.'/page-'.$index.'.enc')), true, flags: JSON_THROW_ON_ERROR);
                foreach ($page as $member) {
                    $members['id:'.$member['actor_user_id']] = $member;
                }
            }
            $progress?->__invoke('Resuming source read at pass '.($firstPass + 1).', offset '.$firstOffset.'.');
        }
        foreach (['created_at', 'updated_at', 'last_kyc_updated_at'] as $pass => $order) {
            if ($pass < $firstPass) {
                continue;
            }
            $offset = $pass === $firstPass ? $firstOffset : 0;
            $pages = 0;
            $progress?->__invoke('Reading User Service: pass '.($pass + 1).'/3 ('.$order.').');
            while ($total === null || $offset < $total) {
                [$pageMembers, $scanned] = $this->readPage($settings, $offset, 500, $order, $total, $allowPartial, $recoveries);
                foreach ($pageMembers as $member) {
                    $members['id:'.$member['actor_user_id']] = $member;
                }
                $offset += $scanned;
                $pages++;
                if (count($members) > $total || $offset > $total) {
                    throw new CustomerSyncException('User list exceeds its reported total.');
                }
                if (! $disk->put($checkpoint.'/page-'.$fileCount.'.enc', Crypt::encryptString(json_encode($pageMembers, JSON_THROW_ON_ERROR)))) {
                    throw new CustomerSyncException('Could not persist a source read checkpoint.');
                }
                $fileCount++;
                $state = [
                    'environment' => $settings['environment'], 'user_url' => $settings['user_url'], 'feedback_url' => $settings['feedback_url'],
                    'allow_partial' => $allowPartial, 'captured_at' => $captured, 'total' => $total,
                    'pass' => $pass, 'offset' => $offset, 'file_count' => $fileCount, 'recoveries' => $recoveries,
                ];
                if (! $disk->put($checkpoint.'/state.enc', Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)))) {
                    throw new CustomerSyncException('Could not persist source read progress.');
                }
                if ($pages % 10 === 0 || count($members) === $total || $offset === $total) {
                    $progress?->__invoke('Observed unique accounts: '.count($members).'/'.$total.'; page offset: '.$offset.'.');
                }
                if (count($members) === $total) {
                    break;
                }
            }
            if (count($members) === $total) {
                return ['members' => array_values($members), 'captured_at' => $captured, 'verified' => false, 'source_total' => $total, 'missing_count' => 0];
            }
        }

        if ($allowPartial && count($members) > 0) {
            return ['members' => array_values($members), 'captured_at' => $captured, 'verified' => false, 'source_total' => $total, 'missing_count' => $total - count($members)];
        }

        $disk->deleteDirectory($checkpoint);
        throw new CustomerSyncException('User Service returned only '.count($members).' distinct accounts out of '.$total.' after three read passes. Previous customer data is unchanged.');
    }

    /** @return array{0: array, 1: int} */
    private function readPage(array $settings, int $offset, int $limit, string $order, ?int &$total, bool $allowPartial, int &$recoveries): array
    {
        try {
            $response = Http::acceptJson()->withToken($settings['user_token'])->timeout(30)->withoutRedirecting()
                ->retry([250, 1000], when: fn ($exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()
                        && ! str_contains(strtolower((string) $exception->response->json('msg')), 'decod')), throw: false)
                ->get(rtrim($settings['user_url'], '/').'/user-service/api/v1/user/', [
                    'role' => 'CUSTOMER', 'limit' => $limit, 'skip' => $offset, 'order_by' => $order,
                ]);
            if ($allowPartial && $response->status() === 500 && str_contains(strtolower((string) $response->json('msg')), 'decod')) {
                $recoveries++;
                if ($recoveries > 128) {
                    throw new CustomerSyncException('Too many source record errors. Previous customer data is unchanged.');
                }
                if ($limit === 1) {
                    return [[], 1];
                }
                $left = intdiv($limit, 2);
                [$a, $countA] = $this->readPage($settings, $offset, $left, $order, $total, $allowPartial, $recoveries);
                [$b, $countB] = $this->readPage($settings, $offset + $left, $limit - $left, $order, $total, $allowPartial, $recoveries);

                return [[...$a, ...$b], $countA + $countB];
            }
            $response->throw();
        } catch (ConnectionException) {
            throw new CustomerSyncException('User Service connection failed. Check USER_SERVICE_BASE_URL, DNS and TLS connectivity.');
        } catch (RequestException $exception) {
            throw $this->httpError('User Service', $exception->response->status());
        }
        $page = Validator::make($response->json(), [
            'error' => 'required|boolean|declined', 'total' => 'required|integer|min:0|max:10000000', 'data' => 'present|array|max:500',
        ])->validate();
        $total ??= (int) $page['total'];
        if ($total !== (int) $page['total'] || ($offset < $total && empty($page['data']))) {
            throw new CustomerSyncException('User list changed or pagination ended before the reported total.');
        }
        if (count($page['data']) > $limit) {
            throw new CustomerSyncException('User Service exceeded the requested page size.');
        }

        return [$this->map($page['data']), count($page['data'])];
    }

    private function export(string $file, string $environment): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new CustomerSyncException('Snapshot file is missing or unreadable.');
        }
        $export = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        Validator::make($export, [
            'environment' => 'required|string', 'snapshot_id' => 'required|string|max:100',
            'captured_at' => 'required|date_format:Y-m-d\TH:i:s\Z|before_or_equal:now',
            'complete' => 'required|boolean|accepted', 'total' => 'required|integer|min:0|max:10000000', 'data' => 'present|array',
        ])->validate();
        if ($export['environment'] !== $environment || count($export['data']) !== (int) $export['total']) {
            throw new CustomerSyncException('Verified export environment or count mismatch.');
        }
        $members = $this->map($export['data']);
        $this->unique($members);

        return ['members' => $members, 'captured_at' => $export['captured_at'], 'source_snapshot_id' => $export['snapshot_id'], 'verified' => true];
    }

    private function map(array $users): array
    {
        Validator::make(['users' => $users], [
            'users.*.id' => 'required|string|max:100', 'users.*.role' => 'required|in:CUSTOMER',
            'users.*.first_name' => 'nullable|string|max:100', 'users.*.last_name' => 'nullable|string|max:100',
        ])->validate();

        return array_map(static fn (array $user): array => [
            'actor_user_id' => $user['id'],
            'display_name' => trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: null,
            'identity_complete' => true,
        ], $users);
    }

    private function unique(array $members): void
    {
        if (count(array_unique(array_column($members, 'actor_user_id'))) !== count($members)) {
            throw new CustomerSyncException('Duplicate account IDs across User Service pages.');
        }
    }

    private function post(array $settings, string $path, string $key, array $body): array
    {
        try {
            $response = Http::acceptJson()->withToken($settings['cohort_token'])->timeout(30)->withoutRedirecting()
                ->withHeaders(['X-Service-ID' => 'user-adapter', 'Idempotency-Key' => $key])
                ->post(rtrim($settings['feedback_url'], '/').'/internal/v1/feedback/'.$path, $body)->throw();
        } catch (ConnectionException) {
            throw new CustomerSyncException('Cohort API connection failed. Check FEEDBACK_SYNC_BASE_URL and its internal route.');
        } catch (RequestException $exception) {
            throw $this->httpError('Cohort API', $exception->response->status());
        }
        if (! in_array($response->status(), [200, 201], true)) {
            throw new CustomerSyncException('Unexpected Cohort API response.');
        }

        return $response->json();
    }

    private function httpError(string $stage, int $status): CustomerSyncException
    {
        $hint = match ($status) {
            401 => $stage === 'User Service' ? 'Check USER_SERVICE_READ_TOKEN and its expiry.' : 'Check FEEDBACK_COHORT_TOKEN against the target FEEDBACK_COHORT_TOKEN_SHA256.',
            403 => $stage === 'User Service' ? 'The read credential needs permission to list customers.' : 'The user-adapter identity needs cohort.write scope.',
            404 => 'Check the base URL and route availability.',
            409 => 'The snapshot or batch conflicts with the original run. Keep the original journal and payload.',
            422 => 'The target rejected the snapshot or member fields.',
            429 => 'Wait for the target rate limit before resuming this run.',
            default => 'Check the target service availability.',
        };

        return new CustomerSyncException($stage.' returned HTTP '.$status.'. '.$hint);
    }
}
