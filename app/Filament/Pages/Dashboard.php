<?php

namespace App\Filament\Pages;

use App\Application\Exports;
use App\Application\ReportFilters;
use App\Filament\Support\FilterFields;
use App\Filament\Widgets\Choices;
use App\Filament\Widgets\Distribution;
use App\Filament\Widgets\LatestFeedback;
use App\Filament\Widgets\Metrics;
use App\Filament\Widgets\Satisfaction;
use App\Filament\Widgets\Trend;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends \Filament\Pages\Dashboard
{
    use HasFiltersForm;

    public function mount(): void
    {
        if (! isset($this->filters['survey_version_id'])) {
            $this->filters = array_replace(ReportFilters::defaults(), array_filter($this->filters ?? [], fn ($v) => $v !== null && $v !== ''));
            $this->getFiltersForm()->fill($this->filters);
        }
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('analytics.read') ?? false;
    }

    public function getTitle(): string
    {
        return __('feedback.dashboard');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('feedback.nav_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('feedback.dashboard');
    }

    public function getSubheading(): ?string
    {
        return __('feedback.report_hint');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components(FilterFields::make());
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('exportPdf')->label('Export PDF')->visible(fn () => auth()->user()->can('report.export') && auth()->user()->can('feedback.read'))->action(function (): void {
            app(Exports::class)->create(auth()->user(), $this->filters ?? [], 'pdf');
            Notification::make()->title(__('feedback.export_queued'))->success()->send();
        }), Action::make('exports')->label(__('feedback.reports'))->url(Reports::getUrl())->visible(fn () => Reports::canAccess())];
    }

    public function getWidgets(): array
    {
        return [Metrics::class, Satisfaction::class, Distribution::class, Trend::class, Choices::class, LatestFeedback::class];
    }
}
