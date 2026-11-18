<?php

namespace App\Filament\Widgets;

use App\Application\Analytics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class Distribution extends ChartWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return auth()->user()?->can('analytics.read') ?? false;
    }

    public function getHeading(): string
    {
        return __('feedback.distribution');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $m = app(Analytics::class)->summary(auth()->user(), $this->pageFilters ?? []);

        return ['labels' => ['1 ★', '2 ★', '3 ★', '4 ★', '5 ★'], 'datasets' => [['label' => __('feedback.responses'), 'data' => array_values($m['distribution']), 'backgroundColor' => ['#b8c4d8', '#8ca0be', '#607d9f', '#b68b36', '#d6b468']]]];
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
        return array_sum($this->getCachedData()['datasets'][0]['data'] ?? []) === 0;
    }
}
