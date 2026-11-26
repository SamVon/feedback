<?php

namespace App\Application;

use App\Models\CohortMember;
use App\Models\FeedbackResponse;
use App\Models\User;
use Carbon\CarbonImmutable;

final class TableExportColumns
{
    private const LABELS = [
        'feedback' => [
            'number' => '#', 'received_at' => 'history_response_time', 'customer_reference' => 'customer',
            'actor_user_id' => 'customer_user_id', 'customer_name' => 'history_customer_name', 'customer_phone' => 'customer_phone',
            'question' => 'question', 'rating' => 'history_satisfaction', 'selected_answers' => 'answers',
            'feedback_text' => 'customer_additional_feedback', 'version.survey.name' => 'survey', 'version.version' => 'version',
            'source' => 'source', 'context.transaction_type' => 'transaction_type',
        ],
        'customers' => [
            'number' => '#', 'phone' => 'customer', 'actor_user_id' => 'customer_user_id', 'display_name' => 'name',
            'contact_phone' => 'customer_phone', 'response_status' => 'response_status', 'response_count' => 'responses',
            'invitation_status' => 'invitation_status', 'latest_at' => 'customer_latest_response',
            'latestFilteredResponse.version.survey.name' => 'survey', 'latest_rating' => 'rating', 'latest_feedback' => 'customer_latest_feedback',
        ],
    ];

    public static function resolve(User $user, string $scope, ?array $columns = null): array
    {
        abort_unless(isset(self::LABELS[$scope]), 422, 'Unknown export table.');
        $explicit = $columns !== null;
        $columns ??= array_values(array_diff(array_keys(self::LABELS[$scope]), $scope === 'feedback'
            ? ['actor_user_id', 'version.survey.name', 'version.version', 'source', 'context.transaction_type']
            : ['actor_user_id', 'invitation_status']));
        abort_unless(count($columns) > 0 && count($columns) <= 20, 422, 'Select export columns.');
        $resolved = [];
        foreach ($columns as $column) {
            abort_unless(is_string($column) && isset(self::LABELS[$scope][$column]) && ! in_array($column, $resolved, true), 422, 'Invalid export column.');
            $permission = self::permission($scope, $column);
            if ($permission && ! $user->can($permission)) {
                if ($explicit) {
                    Access::check($user, $permission);
                }

                continue;
            }
            $resolved[] = $column;
        }
        abort_unless(count($resolved) > 0, 422, 'No accessible export columns.');

        return $resolved;
    }

    private static function permission(string $scope, string $column): ?string
    {
        if (in_array($column, $scope === 'feedback' ? ['customer_reference', 'customer_name', 'customer_phone'] : ['phone', 'display_name', 'contact_phone'], true)) {
            return 'customer_identity.read';
        }
        if ($scope === 'customers' && in_array($column, ['latestFilteredResponse.version.survey.name', 'latest_rating', 'latest_feedback'], true)) {
            return 'feedback.read';
        }

        return null;
    }

    public static function headers(string $scope, array $columns, string $locale): array
    {
        return array_map(fn (string $column): string => self::LABELS[$scope][$column] === '#'
            ? '#' : __('feedback.'.self::LABELS[$scope][$column], [], $locale), $columns);
    }

    public static function feedbackRow(FeedbackResponse $response, array $columns, int $number, string $locale): array
    {
        $labels = fn ($items): string => $items->map(fn ($item): string => $item->label[$locale] ?? $item->label['en'])->join("\n");

        return array_map(fn (string $column): mixed => match ($column) {
            'number' => $number,
            'received_at' => $response->received_at->setTimezone('Asia/Vientiane')->format('d/m/Y H:i'),
            'customer_reference', 'customer_phone' => $response->customer_phone ?? '—',
            'customer_name' => $response->customer_name ?? '—',
            'actor_user_id' => $response->actor_user_id,
            'question' => $labels($response->answers->pluck('question')->unique('id')) ?: '—',
            'rating' => $response->answers->whereNotNull('rating_value')->map(fn ($answer): string => $answer->rating_value.' / 5')->join("\n") ?: '—',
            'selected_answers' => $labels($response->answers->flatMap(fn ($answer) => $answer->options)) ?: '—',
            'feedback_text' => $response->answers->pluck('text_value')->filter(fn ($value): bool => filled($value))->join("\n") ?: '—',
            'version.survey.name' => $response->version->survey->name,
            'version.version' => $response->version->version,
            'source' => __('feedback.history_source_'.$response->source, [], $locale),
            'context.transaction_type' => $response->context?->transaction_type ?? '—',
        }, $columns);
    }

    public static function customerRow(CohortMember $member, array $columns, int $number, string $locale, bool $complete): array
    {
        return array_map(fn (string $column): mixed => match ($column) {
            'number' => $number,
            'phone', 'contact_phone' => $member->phone ?? '—',
            'actor_user_id' => $member->actor_user_id,
            'display_name' => $member->display_name ?? '—',
            'response_status' => __('feedback.'.($member->response_count > 0 ? 'answered' : (! $complete || ! $member->identity_complete ? 'incomplete' : 'unanswered')), [], $locale),
            'response_count' => (int) $member->response_count,
            'invitation_status' => __('feedback.'.(! $member->identity_complete ? 'unknown' : ($member->shown_count ? 'shown' : ($member->invitation_count ? 'eligible' : 'not_invited'))), [], $locale),
            'latest_at' => $member->latest_at ? CarbonImmutable::parse($member->latest_at, 'UTC')->setTimezone('Asia/Vientiane')->format('d/m/Y H:i') : '—',
            'latestFilteredResponse.version.survey.name' => $member->latestFilteredResponse?->version?->survey?->name ?? '—',
            'latest_rating' => ($rating = $member->latestFilteredResponse?->answers->firstWhere('rating_value', '!=', null)?->rating_value) !== null ? $rating.' / 5' : '—',
            'latest_feedback' => $member->latestFilteredResponse?->answers->flatMap(fn ($answer) => collect([$answer->text_value])->filter(fn ($value): bool => filled($value))
                ->merge($answer->options->map(fn ($option): string => $option->label[$locale] ?? $option->label['en'])))->join("\n") ?: '—',
        }, $columns);
    }
}
