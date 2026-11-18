<?php

namespace App\Filament\Pages;

use App\Application\Access;
use App\Application\Exports;
use App\Application\ReportFilters;
use App\Filament\Support\FilterFields;
use App\Models\ExportJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class Reports extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.reports';

    protected static ?int $navigationSort = 7;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filtersForm->fill(ReportFilters::defaults());
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('report.export') && auth()->user()?->can('feedback.read')) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('feedback.nav_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('feedback.reports');
    }

    public function getTitle(): string
    {
        return __('feedback.reports');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components(FilterFields::make())->statePath('filters')->columns(3);
    }

    public function createExport(string $format = 'csv'): void
    {
        app(Exports::class)->create(auth()->user(), $this->filtersForm->getState(), $format);
        Notification::make()->title(__('feedback.export_queued'))->success()->send();
    }

    public function table(Table $table): Table
    {
        Access::check(auth()->user(), 'report.export');

        return $table->query(ExportJob::where('user_id', auth()->id()))->columns([
            TextColumn::make('format')->badge(), TextColumn::make('created_at')->dateTime(), TextColumn::make('status')->badge(), TextColumn::make('snapshot_at')->dateTime(), TextColumn::make('expires_at')->dateTime(), TextColumn::make('error')->wrap(),
        ])->defaultSort('created_at', 'desc')->poll('5s')->recordActions([Action::make('download')->label(__('feedback.download'))->url(fn ($record) => route('exports.download', $record))->visible(fn ($record) => $record->status === 'ready' && $record->expires_at->isFuture())]);
    }
}
