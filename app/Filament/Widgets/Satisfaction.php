<?php

namespace App\Filament\Widgets;

use App\Application\Analytics;

class Satisfaction extends Distribution
{
    public function getHeading(): string
    {
        return __('feedback.satisfaction');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $m = app(Analytics::class)->summary(auth()->user(), $this->pageFilters ?? []);
        $d = $m['distribution'];

        return ['labels' => [__('feedback.satisfied'), __('feedback.neutral'), __('feedback.dissatisfied')], 'datasets' => [['data' => [$d[4] + $d[5], $d[3], $d[1] + $d[2]], 'backgroundColor' => ['#1c8a83', '#dfbb4b', '#ad3535']]]];
    }
}
