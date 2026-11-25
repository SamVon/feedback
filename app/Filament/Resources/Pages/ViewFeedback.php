<?php

namespace App\Filament\Resources\Pages;

use App\Application\Access;
use App\Application\ReportFilters;
use App\Filament\Resources\FeedbackResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class ViewFeedback extends ViewRecord
{
    protected static string $resource = FeedbackResource::class;

    protected string $view = 'filament.resources.pages.view-feedback';

    #[Locked]
    public array $filters = [];

    #[Locked]
    public string $search = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $filters = request()->query('filters', []);
        abort_unless(is_array($filters), 422);
        $this->filters = ReportFilters::validate($filters);
        $search = request()->query('search', '');
        abort_unless(is_string($search) && mb_strlen($search) <= 100, 422);
        $this->search = $search;
    }

    public function getTitle(): string
    {
        return __('feedback.feedback_history_detail');
    }

    public function getRecord(): Model
    {
        Access::check(auth()->user(), 'feedback.read');

        return parent::getRecord();
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
