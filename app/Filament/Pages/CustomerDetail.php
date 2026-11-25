<?php

namespace App\Filament\Pages;

use App\Application\Access;
use App\Application\Customers;
use App\Application\ReportFilters;
use App\Models\CohortMember;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class CustomerDetail extends Page
{
    use WithPagination;

    protected static ?string $slug = 'customers/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.customer-detail';

    #[Locked]
    public string $memberId;

    #[Locked]
    public array $filters = [];

    #[Locked]
    public string $customerSource = 'complete';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(['customers.read', 'feedback.read']) ?? false;
    }

    public function mount(string $record): void
    {
        Access::check(auth()->user(), 'customers.read');
        Access::check(auth()->user(), 'feedback.read');
        $source = request()->query('customer_source', 'complete');
        abort_unless(is_string($source) && in_array($source, ['complete', 'latest_import'], true), 422);
        $this->customerSource = $source;
        $this->memberId = app(Customers::class)->member(auth()->user(), $record)->id;
        $input = request()->query('filters', []);
        abort_unless(is_array($input), 422);
        $this->filters = ReportFilters::validate($input);
        unset($this->filters['actor_user_id']);
    }

    public function getTitle(): string
    {
        return __('feedback.customer_detail_title');
    }

    public function getHeading(): ?string
    {
        return null;
    }

    #[Computed]
    public function customer(): CohortMember
    {
        Access::check(auth()->user(), 'customers.read');
        Access::check(auth()->user(), 'feedback.read');

        return app(Customers::class)->member(auth()->user(), $this->memberId);
    }

    #[Computed]
    public function responses(): LengthAwarePaginator
    {
        Access::check(auth()->user(), 'feedback.read');
        $filters = $this->filters;
        $filters['actor_user_id'] = $this->customer->actor_user_id;

        return ReportFilters::query($filters)->with('answers.question', 'answers.options', 'version.survey', 'context')
            ->orderByDesc('received_at')->orderByDesc('id')->paginate(1, ['*'], 'responsesPage');
    }
}
