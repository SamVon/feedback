<x-filament-panels::page>
    <div class="eg-customers-page">
        <nav class="eg-customer-detail-breadcrumb eg-feedback-breadcrumb" aria-label="{{ __('feedback.customer_rating_history') }}">
            @if (\App\Filament\Pages\Dashboard::canAccess())
                <a href="{{ \App\Filament\Pages\Dashboard::getUrl() }}">{{ __('feedback.dashboard') }}</a>
            @else
                <a href="{{ \App\Filament\Pages\FeedbackCenter::getUrl() }}">{{ __('feedback.feedback_center') }}</a>
            @endif
            <span aria-hidden="true">›</span>
            <span aria-current="page">{{ __('feedback.customer_rating_history') }}</span>
        </nav>
        @include('filament.partials.feedback-tabs', ['active' => 'customers'])
        <section class="eg-customer-search-card" aria-labelledby="customer-list-heading">
            <div class="eg-customer-card-header">
                <h1 id="customer-list-heading">{{ __('feedback.customer_list_title') }}</h1>
                @can('report.export')
                    <div class="eg-customer-export-actions">
                        <x-filament::button outlined size="sm" wire:click="exportRoster('xlsx')" wire:loading.attr="disabled">Export Excel</x-filament::button>
                        <x-filament::button outlined size="sm" wire:click="exportRoster('pdf')" wire:loading.attr="disabled">Export PDF</x-filament::button>
                    </div>
                @endcan
            </div>
            <form wire:submit="searchCustomers" class="eg-customer-search-form">
                <label class="eg-customer-keyword">
                    <span>{{ __('feedback.customer_search') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input wire:model.live.debounce.400ms="tableSearch" :placeholder="__('feedback.customer_search_phone')" />
                    </x-filament::input.wrapper>
                </label>
                {{ $this->filtersForm }}
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled">{{ __('feedback.customer_search') }}</x-filament::button>
            </form>
        </section>
        <section class="eg-customer-list-card" aria-label="{{ __('feedback.customers') }}">
            <div wire:loading.delay role="status">{{ __('feedback.loading') }}</div>
            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
