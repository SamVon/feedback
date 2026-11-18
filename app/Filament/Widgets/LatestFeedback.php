<?php

namespace App\Filament\Widgets;

use App\Application\Access;
use App\Application\ReportFilters;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class LatestFeedback extends TableWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('feedback.read') ?? false;
    }

    public function table(Table $table): Table
    {
        Access::check(auth()->user(), 'feedback.read');

        return $table->heading(__('feedback.latest'))->query(ReportFilters::query($this->pageFilters ?? []))->columns([TextColumn::make('actor_user_id')->label(__('feedback.customer')), TextColumn::make('source')->badge(), TextColumn::make('received_at')->dateTime('d M H:i')])->defaultSort('received_at', 'desc')->paginated([5, 10])->emptyStateHeading(__('feedback.empty'));
    }
}
