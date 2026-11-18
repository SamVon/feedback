<?php

namespace App\Application;

use App\Models\FeedbackResponse;
use Carbon\CarbonImmutable;

final class PopupPolicy
{
    public const MAX_RESPONSES = 3;

    public const COOLDOWN_DAYS = 30;

    /** Published legacy JSON remains immutable; expose the confirmed policy without rewriting it. */
    public static function normalizeRules(array $rules): array
    {
        $rows = $rules['transaction_rules'] ?? array_map(
            fn (string $type): array => ['transaction_type' => $type, 'enabled' => true],
            $rules['transaction_types'] ?? [],
        );
        $rules['transaction_rules'] = array_values(array_map(fn (array $row): array => [
            'transaction_type' => $row['transaction_type'],
            'enabled' => (bool) ($row['enabled'] ?? false),
            'max_responses' => self::MAX_RESPONSES,
            'cooldown_days' => self::COOLDOWN_DAYS,
            'reset_policy' => 'pending_confirmation',
        ], $rows));
        unset($rules['transaction_types'], $rules['cooldown_seconds']);
        $rules['customer_service'] = ChannelPolicy::customerService($rules);
        $rules['popup_schedule'] = ChannelPolicy::schedule($rules);
        $rules['always_on_frequency'] = ChannelPolicy::frequencyProposal();

        return $rules;
    }

    /**
     * Read under the actor lock in the surrounding idempotency transaction before any write.
     * Committed responses are the counter: no mutable duplicate counter or periodic reset exists.
     */
    public function state(string $actor, string $type, array $rules): array
    {
        $row = collect(self::normalizeRules($rules)['transaction_rules'])->firstWhere('transaction_type', $type);
        $aggregate = FeedbackResponse::where('actor_user_id', $actor)
            ->where('source', 'auto_prompt')
            ->whereHas('context', fn ($query) => $query->where('transaction_type', $type))
            ->selectRaw('COUNT(*) as total, MAX(received_at) as last_received_at')->first();
        $count = (int) $aggregate->total;
        $cooldownUntil = $aggregate->last_received_at
            ? CarbonImmutable::parse($aggregate->last_received_at, 'UTC')->addDays(self::COOLDOWN_DAYS)
            : null;
        $reason = match (true) {
            ! ($rules['channels']['auto_prompt'] ?? false), ! ($row['enabled'] ?? false) => 'trigger_disabled',
            ! ChannelPolicy::popupOpen($rules) => 'outside_schedule',
            $count >= self::MAX_RESPONSES => 'response_limit_reached',
            $cooldownUntil?->isFuture() === true => 'cooldown_active',
            default => null,
        };

        return [
            'eligible' => $reason === null,
            'reason' => $reason,
            'transaction_type' => $type,
            'successful_response_count' => $count,
            'max_responses' => self::MAX_RESPONSES,
            'remaining_responses' => max(0, self::MAX_RESPONSES - $count),
            'cooldown_days' => self::COOLDOWN_DAYS,
            'cooldown_until' => $cooldownUntil?->format('Y-m-d\TH:i:s\Z'),
            'reset_policy' => 'pending_confirmation',
        ];
    }
}
