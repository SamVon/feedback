<?php

namespace App\Filament\Pages;

use App\Application\Access;
use App\Application\Analytics;
use App\Application\Exports;
use App\Application\ReportFilters;
use App\Filament\Resources\FeedbackResource;
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
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Collection;

class Dashboard extends \Filament\Pages\Dashboard
{
    use HasFiltersForm {
        getFiltersSessionKey as private baseFiltersSessionKey;
    }

    protected string $view = 'filament.pages.dashboard';

    public function summary(): array
    {
        return app(Analytics::class)->summary(auth()->user(), $this->dataFilters());
    }

    public function respondentGenders(): array
    {
        return app(Analytics::class)->respondentGenders(auth()->user(), $this->dataFilters());
    }

    public function choiceSummary(): array
    {
        $filters = $this->dataFilters();
        $analytics = app(Analytics::class);
        $responses = ReportFilters::query($filters);
        $question = isset($filters['question_id']) ? Question::where('type', 'choice')->find($filters['question_id']) : null;
        $question ??= $analytics->latestAnsweredQuestion($responses, 'choice');

        if (! $question) {
            return [];
        }

        $filters['question_id'] = $question->id;
        unset($filters['rating']);

        return [
            'question' => $question->label[app()->getLocale()] ?? $question->label['en'],
            'options' => $analytics->summary(auth()->user(), $filters, $responses->select('id'))['choices'],
        ];
    }

    public function recentFeedback(): Collection
    {
        if (! auth()->user()->can('feedback.read')) {
            return collect();
        }

        return ReportFilters::query($this->dataFilters())
            ->whereHas('answers', fn ($query) => $query->whereNotNull('text_value')->where('text_value', '!=', ''))
            ->with('answers.question')->latest('received_at')->limit(3)->get();
    }

    public function dataFilters(): array
    {
        return ['exclude_demo' => true, 'default_rating_question' => true] + ($this->filters ?? []);
    }

    #[Unexposed]
    public function getFiltersSessionKey(): string
    {
        return $this->baseFiltersSessionKey().'.all-responses';
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

    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [
            static::getRouteName(),
            Customers::getRouteName(),
            CustomerDetail::getRouteName(),
            FeedbackResource::getRouteBaseName().'.*',
        ];
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function filtersForm(Schema $schema): Schema
    {
        $fields = FilterFields::make();

        foreach (array_slice($fields, 0, 2) as $dateField) {
            $dateField->extraFieldWrapperAttributes(['class' => 'eg-dashboard-date-field'])
                ->extraInputAttributes(['class' => 'eg-dashboard-date-input']);
        }

        return $schema->columns(['default' => 1, 'md' => 2])->components([
            $fields[0]->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                if ($state && $get('until') && $state > $get('until')) {
                    $set('until', $state);
                }
            }),
            $fields[1]->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
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
        return [Action::make('exportPng')->label(__('feedback.export_dashboard_png'))->icon('heroicon-o-photo')
            ->visible(fn () => auth()->user()->can('report.export') && auth()->user()->can('feedback.read'))
            ->extraAttributes(['data-dashboard-export-button' => '',
                'data-export-working' => __('feedback.export_dashboard_working'),
                'data-export-error' => __('feedback.export_dashboard_error'),
                'data-export-success' => __('feedback.export_dashboard_success'),
            ])->action(function (): void {
                Access::check(auth()->user(), 'analytics.read');
                Access::check(auth()->user(), 'report.export');
                Access::check(auth()->user(), 'feedback.read');
                $this->dispatch('dashboard-export-png');
            }), Action::make('exportPdf')->label('Export PDF')->outlined()->visible(fn () => auth()->user()->can('report.export') && auth()->user()->can('feedback.read'))->action(function (): void {
                app(Exports::class)->create(auth()->user(), $this->dataFilters(), 'pdf');
                Notification::make()->title(__('feedback.export_queued'))->success()->send();
            }), Action::make('exports')->label(__('feedback.reports'))->url(Reports::getUrl())->visible(fn () => Reports::canAccess())];
    }

    public function getWidgets(): array
    {
        return [Trend::class];
    }
}
