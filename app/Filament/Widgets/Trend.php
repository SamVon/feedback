<?php

namespace App\Filament\Widgets;

use App\Application\Analytics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class Trend extends ChartWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return auth()->user()?->can('analytics.read') ?? false;
    }

    public function getHeading(): string
    {
        return __('feedback.trend');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $m = app(Analytics::class)->summary(auth()->user(), $this->pageFilters ?? []);
        $rows = $m['trend'];

        return ['labels' => array_column($rows, 'date'), 'datasets' => [['label' => __('feedback.average'), 'data' => array_column($rows, 'average'), 'borderColor' => '#7c87ff', 'backgroundColor' => '#d9ddff', 'pointRadius' => 4, 'pointHoverRadius' => 6, 'borderWidth' => 2, 'fill' => false]]];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['min' => 0, 'max' => 5, 'ticks' => ['stepSize' => 1]]], 'plugins' => ['legend' => ['display' => false]]];
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
        return empty($this->getCachedData()['labels']);
    }
}
