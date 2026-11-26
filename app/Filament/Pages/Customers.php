<?php

namespace App\Filament\Pages;

use App\Application\Access;
use App\Application\CustomerRosterExport;
use App\Application\Customers as CustomerService;
use App\Application\ReportFilters;
use App\Filament\Support\FilterFields;
use App\Models\CohortMember;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Customers extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.customers';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public ?array $filters = [];

    public string $customerSource = 'complete';

    public function mount(): void
    {
        $source = request()->query('customer_source', 'complete');
        abort_unless(is_string($source) && in_array($source, ['complete', 'latest_import'], true), 422);
        $this->customerSource = $source;
        $input = request()->query('filters');
        abort_unless($input === null || is_array($input), 422);
        $this->filters = $input === null ? collect(ReportFilters::defaults())->except(['survey_version_id', 'question_id'])->all() : ReportFilters::validate($input);
        $this->filtersForm->fill($this->filters);
    }

    public function updatedFilters(): void
    {
        $this->resetValidation();
        $this->resetPage();
    }

    public function updatedCustomerSource(): void
    {
        Access::check(auth()->user(), 'customers.read');
        abort_unless(in_array($this->customerSource, ['complete', 'latest_import'], true), 422);
        $this->resetPage();
    }

    private function customerQuery(): Builder
    {
        try {
            return app(CustomerService::class)->listing(auth()->user(), $this->currentFilters(), $this->customerSource, answeredOnly: true);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('filters.'.$field, $messages[0]);
            }

            return app(CustomerService::class)->listing(auth()->user(), [], answeredOnly: true)->whereRaw('1=0');
        }
    }

    private function currentFilters(): array
    {
        return array_replace($this->filters ?? [], ['source' => 'auto_prompt']);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('customers.read') ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('feedback.nav_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('feedback.customers');
    }

    public function getTitle(): string
    {
        return __('feedback.customers');
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components(FilterFields::customerList())->statePath('filters')->columns(['default' => 1, 'md' => 2, 'xl' => 5])->live();
    }

    public function searchCustomers(): void
    {
        Access::check(auth()->user(), 'customers.read');
        $this->filtersForm->getState();
        $this->resetPage();
    }

    public function exportRoster(string $format): StreamedResponse
    {
        Access::check(auth()->user(), 'customers.read');
        Access::check(auth()->user(), 'report.export');
        $this->filtersForm->getState();

        return app(CustomerRosterExport::class)->download(auth()->user(), $this->getFilteredSortedTableQuery(), $format, $this->customerSource,
            array_keys($this->getTable()->getVisibleColumns()));
    }

    public function table(Table $table): Table
    {
        return $table->query(fn (): Builder => $this->customerQuery())->columns([
            TextColumn::make('number')->label('#')->rowIndex(),
            TextColumn::make('phone')->label(__('feedback.customer'))->default('—')
                ->description(fn (CohortMember $record): ?string => $record->cohort_snapshot_id === null && ! filled($record->display_name) && ! filled($record->phone) ? __('feedback.customer_profile_pending') : ($record->app_reported_phone && filled($record->phone) ? __('feedback.customer_app_reported') : null))
                ->visible(fn (): bool => auth()->user()->can('customer_identity.read')),
            TextColumn::make('actor_user_id')->label(__('feedback.customer_user_id'))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('display_name')->label(__('feedback.name'))->placeholder('—')
                ->visible(fn (): bool => auth()->user()->can('customer_identity.read')),
            TextColumn::make('contact_phone')->label(__('feedback.customer_phone'))->state(fn (CohortMember $record): ?string => $record->phone)->placeholder('—')
                ->visible(fn (): bool => auth()->user()->can('customer_identity.read')),
            TextColumn::make('response_status')->label(__('feedback.response_status'))
                ->state(fn (): string => __('feedback.answered'))->badge(),
            TextColumn::make('response_count')->label(__('feedback.responses'))->numeric(),
            TextColumn::make('invitation_status')->label(__('feedback.invitation_status'))
                ->state(fn (CohortMember $record): string => __('feedback.'.(! $record->identity_complete ? 'unknown'
                    : ($record->shown_count ? 'shown' : ($record->invitation_count ? 'eligible' : 'not_invited')))))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('latest_at')->label(__('feedback.customer_latest_response'))->dateTime('d/m/Y H:i', timezone: 'Asia/Vientiane')->placeholder('—'),
            TextColumn::make('latestFilteredResponse.version.survey.name')->label(__('feedback.survey'))->wrap()->placeholder('—')->visible(fn (): bool => auth()->user()->can('feedback.read')),
            ViewColumn::make('latest_rating')->label(__('feedback.rating'))->view('filament.partials.customer-rating')->visible(fn (): bool => auth()->user()->can('feedback.read')),
            ViewColumn::make('latest_feedback')->label(__('feedback.customer_latest_feedback'))->view('filament.partials.customer-latest-feedback')->visible(fn (): bool => auth()->user()->can('feedback.read')),
        ])->defaultSort('cohort_members.actor_user_id')->paginated([10, 25, 50])
            ->searchable()
            ->searchUsing(fn (Builder $query, string $search): Builder => ReportFilters::searchPhone($query, $search, 'phone', 'cohort_members.id'))
            ->searchPlaceholder(__('feedback.customer_search_phone'))
            ->recordActions([Action::make('history')->label(__('feedback.details'))
                ->authorize(fn (): bool => auth()->user()?->can('feedback.read') ?? false)
                ->url(fn (CohortMember $record): string => CustomerDetail::getUrl(['record' => $record->id, 'filters' => ReportFilters::validate($this->currentFilters()), 'customer_source' => $this->customerSource])),
            ])->emptyStateHeading(__('feedback.empty'));
    }
}
