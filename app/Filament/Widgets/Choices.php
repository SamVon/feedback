<?php

namespace App\Filament\Widgets;

use App\Application\Analytics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class Choices extends ChartWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return auth()->user()?->can('analytics.read') ?? false;
    }

    public function getHeading(): string
    {
        return __('feedback.choices');
    }

    public function getDescription(): ?string
    {
        return __('feedback.choice_denominator_hint');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $m = app(Analytics::class)->summary(auth()->user(), $this->pageFilters ?? []);

        return ['labels' => array_column($m['choices'], 'label'), 'datasets' => [['label' => '%', 'data' => array_column($m['choices'], 'percent'), 'backgroundColor' => '#b68b36']]];
    }

    public function getEmptyStateHeading(): string
    {
        return __('feedback.no_data');
    }

    public function getEmptyStateDescription(): ?string
    {
        return __('feedback.question_hint');
    }

    public function isEmpty(): bool
    {
        return empty(array_filter($this->getCachedData()['datasets'][0]['data'] ?? [], fn ($value) => $value !== null));
    }
}
