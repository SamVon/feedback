<x-filament-panels::page>
    <div class="eg-customers-page eg-feedback-history-page">
        <nav class="eg-customer-detail-breadcrumb eg-feedback-breadcrumb" aria-label="{{ __('feedback.feedback_history') }}">
            @if (\App\Filament\Pages\Dashboard::canAccess())
                <a href="{{ \App\Filament\Pages\Dashboard::getUrl() }}">{{ __('feedback.dashboard') }}</a>
            @else
                <a href="{{ \App\Filament\Pages\FeedbackCenter::getUrl() }}">{{ __('feedback.feedback_center') }}</a>
            @endif
            <span aria-hidden="true">›</span>
            <span aria-current="page">{{ __('feedback.feedback_history') }}</span>
        </nav>
        @include('filament.partials.feedback-tabs', ['active' => 'feedback'])
        <section class="eg-customer-search-card eg-customer-list-card eg-feedback-sheet" aria-labelledby="feedback-history-heading">
            <div class="eg-customer-card-header">
                <h1 id="feedback-history-heading">{{ __('feedback.feedback_history') }}</h1>
                @can('report.export')
                    <div class="eg-customer-export-actions">
                        <x-filament::button outlined size="sm" wire:click="exportHistory('xlsx')" wire:loading.attr="disabled">Export Excel</x-filament::button>
                        <x-filament::button outlined size="sm" wire:click="exportHistory('pdf')" wire:loading.attr="disabled">Export PDF</x-filament::button>
                    </div>
                @endcan
            </div>
            <form wire:submit="searchFeedback" class="eg-customer-search-form eg-feedback-search-form">
                <label class="eg-customer-keyword">
                    <span>{{ __('feedback.customer_search') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input wire:model.live.debounce.400ms="tableSearch" maxlength="100" :placeholder="__('feedback.customer_search_phone')" />
                    </x-filament::input.wrapper>
                </label>
                {{ $this->filtersForm }}
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled">{{ __('feedback.customer_search') }}</x-filament::button>
            </form>
            <div class="eg-feedback-table-region" aria-label="{{ __('feedback.feedback_history') }}">
                <div wire:loading.delay role="status" class="eg-feedback-loading">{{ __('feedback.loading') }}</div>
                {{ $this->table }}
            </div>
            <details class="eg-feedback-advanced">
                <summary>{{ __('feedback.history_advanced_filters') }}</summary>
                {{ $this->advancedFiltersForm }}
            </details>
        </section>
    </div>
</x-filament-panels::page>
