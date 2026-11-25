<x-filament-panels::page>
    @php($state = $this->snapshotState())
    <div class="eg-customers-page">
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
                        <x-filament::input wire:model.live.debounce.400ms="tableSearch" :placeholder="__('feedback.customer_search_id')" />
                    </x-filament::input.wrapper>
                </label>
                {{ $this->filtersForm }}
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled">{{ __('feedback.customer_search') }}</x-filament::button>
            </form>
        </section>
        <section class="eg-customer-list-card" aria-label="{{ __('feedback.customers') }}">
            <nav class="eg-customer-status-tabs" aria-label="{{ __('feedback.response_status') }}">
                @foreach (['' => 'customer_all', 'answered' => 'answered', 'unanswered' => 'unanswered', 'incomplete' => 'incomplete'] as $value => $label)
                    <button type="button" wire:click="setCustomerStatus('{{ $value }}')" @if (($this->tableFilters['response_status']['value'] ?? '') === $value) aria-current="page" @endif>{{ __('feedback.'.$label) }}</button>
                @endforeach
            </nav>
            <div class="eg-customer-snapshot" role="status">
                <label>
                    <span>{{ __('feedback.customer_data_source') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="customerSource">
                            <option value="complete">{{ __('feedback.customer_source_complete') }}</option>
                            <option value="latest_import">{{ __('feedback.customer_source_import') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <span @class(['eg-customer-warning' => $state['incomplete']])>{{ $state['incomplete'] ? __($this->customerSource === 'latest_import' ? 'feedback.customer_import_incomplete' : 'feedback.incomplete_snapshot') : __('feedback.complete_snapshot') }}</span>
                <span>{{ __('feedback.snapshot_at') }} {{ $state['selected']?->captured_at->setTimezone('Asia/Vientiane')->format('d/m/Y H:i') ?? '—' }}</span>
            </div>
            @if ($this->customerSource === 'latest_import' && $state['selected']?->status !== 'complete')
                <p class="eg-customer-filter-hint">{{ __('feedback.customer_import_preview_hint') }}</p>
            @endif
            <p class="eg-customer-filter-hint">{{ __('feedback.customer_unanswered_hint') }}</p>
            <div wire:loading.delay role="status">{{ __('feedback.loading') }}</div>
            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
