<x-filament-panels::page>
    <section class="eg-settings-panel" aria-labelledby="settings-heading">
        <h1 id="settings-heading">{{ __('feedback.settings') }}</h1>
        <a class="eg-settings-service-card" href="{{ \App\Filament\Resources\CustomerServiceResource::getUrl() }}">
            @include('filament.partials.customer-service-icon', ['service' => ['icon' => 'headset']])
            <span>Customer Service</span>
        </a>
    </section>
</x-filament-panels::page>
