<x-filament-panels::page>
    @php($backUrl = \App\Filament\Resources\FeedbackResource::getUrl('index', ['filters' => $this->filters, 'search' => $this->search]))
    <div class="eg-customer-detail-page">
        <nav class="eg-customer-detail-breadcrumb" aria-label="{{ __('feedback.feedback_history') }}">
            <a href="{{ $backUrl }}">{{ __('feedback.feedback_history') }}</a>
            <span aria-hidden="true">›</span>
            <span>{{ __('feedback.feedback_history_detail') }}</span>
        </nav>
        @include('filament.partials.response', ['response' => $this->getRecord()->loadMissing('answers.question', 'answers.options', 'context', 'version.survey'), 'backUrl' => $backUrl])
    </div>
</x-filament-panels::page>
