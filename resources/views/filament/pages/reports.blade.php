<x-filament-panels::page>
<form wire:submit="createExport">{{ $this->filtersForm }}<x-filament::button type="submit" style="margin-top:1rem" wire:loading.attr="disabled">{{ __('feedback.create_export') }}</x-filament::button><x-filament::button type="button" wire:click="createExport('xlsx')" wire:loading.attr="disabled">Export Excel</x-filament::button>
<x-filament::button type="button" wire:click="createExport('pdf')" wire:loading.attr="disabled">Export PDF</x-filament::button></form>
<x-filament::button tag="a" href="{{ \App\Filament\Resources\FeedbackResource::getUrl() }}" color="gray">{{ __('feedback.feedback') }}</x-filament::button>
<div wire:loading.delay role="status">{{ __('feedback.loading') }}</div>
{{ $this->table }}
</x-filament-panels::page>
