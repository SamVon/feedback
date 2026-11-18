<?php

namespace App\Filament\Widgets;

use App\Application\Analytics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class Metrics extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return auth()->user()?->can('analytics.read') ?? false;
    }

    protected function getStats(): array
    {
        $m = app(Analytics::class)->summary(auth()->user(), $this->pageFilters ?? []);

        return [Stat::make(__('feedback.average'), $m['average'] ?? __('feedback.no_data'))->description($m['rating_count'].' '.__('feedback.ratings'))->color('primary'), Stat::make('CSAT', $m['csat'] === null ? __('feedback.no_data') : $m['csat'].'%')->description(__('feedback.csat_hint')), Stat::make(__('feedback.responses'), number_format($m['response_count'])), Stat::make(__('feedback.unique_customers'), number_format($m['unique_customers']))];
    }
}
