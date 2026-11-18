<?php

namespace App\Filament\Pages;

use App\Application\Access;
use App\Application\Customers as CustomerService;
use App\Application\ReportFilters;
use App\Filament\Support\FilterFields;
use App\Models\CohortMember;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class Customers extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.customers';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = ReportFilters::defaults();
        $this->filtersForm->fill($this->filters);
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

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components(FilterFields::make())->statePath('filters')->columns(3)->live();
    }

    public function snapshotState(): array
    {
        return app(CustomerService::class)->snapshot(auth()->user());
    }

    public function table(Table $table): Table
    {
        $state = $this->snapshotState();
        $snapshot = $state['complete'] ?? $state['latest'];

        return $table->query(CohortMember::where('cohort_snapshot_id', $snapshot?->id)->with('snapshot'))->columns([
            TextColumn::make('actor_user_id')->label(__('feedback.customer'))->searchable(),
            TextColumn::make('display_name')->label(__('feedback.name'))->state(function ($record) {
                Access::check(auth()->user(), 'customer_identity.read');

                return $record->display_name;
            })->visible(fn () => auth()->user()->can('customer_identity.read')),
            TextColumn::make('response_status')->label(__('feedback.response_status'))->state(fn ($record) => __('feedback.'.app(CustomerService::class)->detail(auth()->user(), $record, $this->filters, false)['response_status']))->badge(),
            TextColumn::make('invitation_status')->label(__('feedback.invitation_status'))->state(fn ($record) => __('feedback.'.app(CustomerService::class)->detail(auth()->user(), $record, $this->filters, false)['invitation_status'])),
        ])->recordActions([Action::make('history')->label(__('feedback.details'))->modalContent(function (CohortMember $record) {
            $detail = app(CustomerService::class)->detail(auth()->user(), $record, $this->filters);
            $filters = $this->filters;
            $filters['actor_user_id'] = $record->actor_user_id;
            $history = auth()->user()->can('feedback.read') ? ReportFilters::query($filters)->with('answers.question', 'answers.options')->latest('received_at')->limit(20)->get() : collect();

            return view('filament.partials.customer', compact('detail', 'history'));
        })->modalSubmitAction(false)])->emptyStateHeading(__('feedback.no_snapshot'));
    }
}
