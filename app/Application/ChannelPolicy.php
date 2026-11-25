<?php

namespace App\Application;

use App\Models\SurveyVersion;
use Carbon\CarbonImmutable;

final class ChannelPolicy
{
    public static function customerService(array $rules): array
    {
        return array_replace_recursive([
            'enabled' => true, 'icon' => 'star', 'icon_path' => null,
            'name' => ['en' => 'Feedback & rating', 'lo' => 'ໃຫ້ດາວ ແລະ ຄຳຕິຊົມ'],
            'description' => ['en' => '', 'lo' => ''],
        ], $rules['customer_service'] ?? []);
    }

    public static function customerServiceConfig(array $rules): array
    {
        $service = self::customerService($rules);
        $service['enabled'] = self::feedbackPageEnabled($rules);
        $path = $service['icon_path'];
        unset($service['icon_path']);
        $service['icon_url'] = filled($path) ? asset('storage/'.$path) : null;

        return $service;
    }

    public static function feedbackPageEnabled(array $rules): bool
    {
        return (bool) ($rules['channels']['always_on'] ?? false) && (bool) self::customerService($rules)['enabled'];
    }

    public static function schedule(array $rules): array
    {
        return array_replace(['starts_at' => null, 'ends_at' => null], $rules['popup_schedule'] ?? []);
    }

    public static function popupOpen(array $rules): bool
    {
        $schedule = self::schedule($rules);

        return (! $schedule['starts_at'] || now()->gte(CarbonImmutable::parse($schedule['starts_at'])))
            && (! $schedule['ends_at'] || now()->lt(CarbonImmutable::parse($schedule['ends_at'])));
    }

    public static function surveySupports(SurveyVersion $version, string $source): bool
    {
        return in_array($source, $version->channels ?? ['auto_prompt', 'always_on'], true);
    }

    public static function content(SurveyVersion $version): array
    {
        return array_replace_recursive([
            'title' => ['en' => 'Feedback & rating', 'lo' => 'ໃຫ້ຄະແນນ ແລະ ຄຳຄິດເຫັນ'],
            'intro' => ['en' => 'Your feedback helps us improve our service.', 'lo' => 'ຄຳຄິດເຫັນຂອງທ່ານຊ່ວຍໃຫ້ພວກເຮົາພັດທະນາການບໍລິການ.'],
            'submit' => ['en' => 'Send feedback', 'lo' => 'ສົ່ງແບບສຳຫຼວດ'],
            'cancel' => ['en' => 'Cancel', 'lo' => 'ຍົກເລີກ'],
            'thank_you' => ['en' => 'Thank you for your feedback.', 'lo' => 'Easy Gold ຂອບໃຈ'],
        ], $version->content ?? []);
    }

    public static function frequencyProposal(): array
    {
        return ['limit' => 3, 'unit' => 'day', 'status' => 'pending_confirmation', 'enforced' => false];
    }
}
