<x-filament-panels::page>
    <div class="eg-customer-service-page">
        <nav class="eg-customer-detail-breadcrumb" aria-label="Customer Service">
            <a href="{{ \App\Filament\Pages\Settings::getUrl() }}">{{ __('feedback.settings') }}</a>
            <span aria-hidden="true">›</span><span>Customer Service</span>
        </nav>
        <h1>{{ __('feedback.customer_service_intro') }}</h1>
        @if ($this->hasUnpublishedSettings())
            <p class="eg-cs-pending-notice" role="status">{{ __('feedback.customer_service_pending_hint') }}</p>
        @endif
        <section class="eg-cs-list-card" aria-label="{{ __('feedback.customer_service_feedback') }}">
            {{ $this->table }}
        </section>
        <a class="eg-cs-history-link" href="{{ \App\Filament\Resources\RuleResource::getUrl() }}">{{ __('feedback.customer_service_history') }}</a>
    </div>
</x-filament-panels::page>
