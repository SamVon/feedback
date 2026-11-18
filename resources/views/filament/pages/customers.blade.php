<x-filament-panels::page>
@php($state=$this->snapshotState())
<div class="eg-banner {{ $state['incomplete'] ? 'eg-warning' : '' }}">
<strong>{{ $state['incomplete'] ? __('feedback.incomplete_snapshot') : __('feedback.complete_snapshot') }}</strong>
<p>{{ __('feedback.snapshot_at') }}: {{ ($state['complete'] ?? $state['latest'])?->captured_at->setTimezone('Asia/Vientiane')->format('d M Y H:i') ?? '—' }} · Asia/Vientiane · {{ __('feedback.account_counting') }}</p>
</div>
{{ $this->filtersForm }}
<div wire:loading.delay role="status">{{ __('feedback.loading') }}</div>
{{ $this->table }}
</x-filament-panels::page>