<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FeedbackResource;
use App\Filament\Resources\OtherQuestionResource;
use App\Filament\Resources\RatingQuestionResource;
use App\Filament\Resources\RuleResource;
use App\Filament\Resources\SurveyResource;
use Filament\Pages\Page;

class FeedbackCenter extends Page
{
    protected string $view = 'filament.pages.feedback-center';

    protected static ?int $navigationSort = 0;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-s-star';

    public static function getNavigationLabel(): string
    {
        return __('feedback.feedback_center');
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public function getTitle(): string
    {
        return __('feedback.feedback_center');
    }

    public function getHeading(): ?string
    {
        return null;
    }

    /** @return array<int, array{label: string, icon: string, url: string}> */
    public function menuCards(): array
    {
        $cards = [
            [Customers::canAccess(), 'customers', 'heroicon-o-users', Customers::getUrl()],
            [Reports::canAccess(), 'feedback_reports', 'heroicon-s-clipboard-document-list', Reports::getUrl()],
            [FeedbackResource::canViewAny(), 'feedback', 'heroicon-o-chat-bubble-left-right', FeedbackResource::getUrl()],
            [RatingQuestionResource::canViewAny(), 'rating_questions', 'heroicon-s-star', RatingQuestionResource::getUrl()],
            [OtherQuestionResource::canViewAny(), 'other_questions', 'heroicon-s-question-mark-circle', OtherQuestionResource::getUrl()],
            [SurveyResource::canViewAny(), 'surveys', 'heroicon-o-clipboard-document-list', SurveyResource::getUrl()],
            [RuleResource::canViewAny(), 'manage_rules', 'heroicon-s-cog-6-tooth', RuleResource::getUrl()],
        ];

        return collect($cards)->filter(fn (array $card): bool => $card[0])->map(fn (array $card): array => [
            'label' => __('feedback.'.$card[1]), 'icon' => $card[2], 'url' => $card[3],
        ])->values()->all();
    }
}
