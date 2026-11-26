<?php

namespace App\Filament\Resources\Pages;

use App\Application\Access;
use App\Application\Exports;
use App\Application\ReportFilters;
use App\Filament\Pages\Reports;
use App\Filament\Resources\FeedbackResource;
use App\Filament\Support\FilterFields;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListFeedback extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    protected string $view = 'filament.resources.pages.list-feedback';

    public ?array $filters = [];

    public function mount(): void
    {
        parent::mount();
        $input = request()->query('filters');
        abort_unless($input === null || is_array($input), 422);
        $this->filters = $input === null ? collect(ReportFilters::defaults())->except(['survey_version_id', 'question_id'])->all() : ReportFilters::validate($input);
        $search = request()->query('search', '');
        abort_unless(is_string($search) && mb_strlen($search) <= 100, 422);
        $this->tableSearch = $search;
        $this->filtersForm->fill($this->filters);
    }

    public function getTitle(): string
    {
        return __('feedback.feedback_history');
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function updatedFilters(): void
    {
        $this->resetValidation();
        $this->resetPage();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components(collect(FilterFields::customerList())->filter(fn ($field): bool => in_array($field->getName(), ['from', 'until'], true))->all())
            ->statePath('filters')->columns(['default' => 1, 'md' => 2])->live();
    }

    public function advancedFiltersForm(Schema $schema): Schema
    {
        return $schema->components(collect(FilterFields::make())->reject(fn ($field): bool => in_array($field->getName(), ['from', 'until', 'actor_user_id', 'source'], true))->all())
            ->statePath('filters')->columns(['default' => 1, 'md' => 2, 'xl' => 4])->live();
    }

    public function dataFilters(): array
    {
        Access::check(auth()->user(), 'feedback.read');
        $filters = array_replace($this->filters ?? [], ['source' => 'always_on']);
        try {
            return ReportFilters::validate($filters);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors['filters.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public function table(Table $table): Table
    {
        return parent::table($table)->modifyQueryUsing(function (Builder $query): Builder {
            try {
                return $query->whereIn('feedback_responses.id', ReportFilters::query($this->dataFilters())->select('id'));
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $this->addError($field, $messages[0]);
                }

                return $query->whereRaw('1=0');
            }
        });
    }

    public function searchFeedback(): void
    {
        $this->dataFilters();
        $this->resetPage();
    }

    public function exportHistory(string $format): ?StreamedResponse
    {
        Access::check(auth()->user(), 'report.export');
        $filters = $this->dataFilters();
        $job = app(Exports::class)->create(auth()->user(), $filters, $format, $this->getFilteredTableQuery(), [
            'columns' => array_keys($this->getTable()->getVisibleColumns()),
            'sort_direction' => $this->getTableSortDirection() ?? 'desc',
        ]);
        $job->refresh();
        if ($job->status === 'ready') {
            return app(Exports::class)->download(auth()->user(), $job);
        }
        Notification::make()->title(__('feedback.export_queued'))->success()
            ->actions([Action::make('reports')->label(__('feedback.reports'))->url(Reports::getUrl())])->send();

        return null;
    }
}
