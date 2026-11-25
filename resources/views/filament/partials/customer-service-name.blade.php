@php
    $record = $getRecord();
    $service = \App\Application\ChannelPolicy::customerServiceConfig($record->rules);
@endphp
<div class="eg-cs-service-name">
    @include('filament.partials.customer-service-icon', ['service' => $service])
    <div>
        <span>{{ $service['name'][app()->getLocale()] ?? $service['name']['en'] }}</span>
        @if ($record->status !== 'published')<p class="eg-cs-pending-label">{{ __('feedback.customer_service_pending') }}</p>@endif
    </div>
</div>
