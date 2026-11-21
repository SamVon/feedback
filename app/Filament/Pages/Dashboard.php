<?php

namespace App\Filament\Pages;

use App\Application\Analytics;
use App\Application\Exports;
use App\Application\ReportFilters;
use App\Filament\Support\FilterFields;
use App\Filament\Widgets\Trend;
use App\Models\Question;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class Dashboard extends \Filament\Pages\Dashboard
{
    use HasFiltersForm;

    protected string $view = 'filament.pages.dashboard';

    public function summary(): array
    {
        return app(Analytics::class)->summary(auth()->user(), $this->filters ?? []);
    }

    public function choiceSummary(): array
    {
        $filters = $this->filters ?? [];
        $question = Question::where('survey_version_id', $filters['survey_version_id'] ?? null)->where('type', 'choice')->orderBy('position')->first();

        if (! $question) {
            return [];
        }

        $members = ReportFilters::query($filters)->select('id');
        $filters['question_id'] = $question->id;
        unset($filters['rating']);

        return app(Analytics::class)->summary(auth()->user(), $filters, $members)['choices'];
    }

    public function recentFeedback(): Collection
    {
        if (! auth()->user()->can('feedback.read')) {
            return collect();
        }

        return ReportFilters::query($this->filters ?? [])
            ->whereHas('answers', fn ($query) => $query->whereNotNull('text_value')->where('text_value', '!=', ''))
            ->with('answers.question')->latest('received_at')->limit(3)->get();
    }

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
        $fields = FilterFields::make();

        foreach (array_slice($fields, 0, 2) as $dateField) {
            $dateField->extraFieldWrapperAttributes(['class' => 'eg-dashboard-date-field'])
                ->extraInputAttributes(['class' => 'eg-dashboard-date-input']);
        }

        return $schema->columns(['default' => 1, 'md' => 2])->components([
            $fields[0]->maxDate(fn (Get $get) => $get('until'))->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                if ($state && $get('until') && $state > $get('until')) {
                    $set('until', $state);
                }
            }),
            $fields[1]->minDate(fn (Get $get) => $get('from'))->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                if ($state && $get('from') && $state < $get('from')) {
                    $set('from', $state);
                }
            }),
            Section::make(__('feedback.dashboard_filters'))
                ->schema(array_slice($fields, 2))->columns(3)->collapsible()->collapsed()->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('exportPdf')->label('Export PDF')->outlined()->visible(fn () => auth()->user()->can('report.export') && auth()->user()->can('feedback.read'))->action(function (): void {
            app(Exports::class)->create(auth()->user(), $this->filters ?? [], 'pdf');
            Notification::make()->title(__('feedback.export_queued'))->success()->send();
        }), Action::make('exports')->label(__('feedback.reports'))->url(Reports::getUrl())->visible(fn () => Reports::canAccess())];
    }

    public function getWidgets(): array
    {
        return [Trend::class];
    }
}
