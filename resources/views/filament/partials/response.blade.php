<div class="eg-customer-detail-page">
    <section class="eg-customer-detail-card">
        <div class="eg-customer-detail-title">
            <h1>{{ __('feedback.feedback_history_detail') }}</h1>
            @isset($backUrl)<x-filament::button tag="a" :href="$backUrl" outlined color="gray" size="sm">{{ __('feedback.back') }}</x-filament::button>@endisset
        </div>
        <div class="eg-customer-detail-profile">
            <div class="eg-customer-detail-avatar"><img src="{{ asset('images/easy-gold-mark.svg') }}" alt="Easy Gold" /></div>
            <dl>
                <div><dt>{{ __('feedback.customer_user_id') }}</dt><dd>{{ $response->actor_user_id }}</dd></div>
                @can('customer_identity.read')
                    <div><dt>{{ __('feedback.name') }}</dt><dd>{{ $response->customer_name ?: '—' }}</dd></div>
                    <div><dt>{{ __('feedback.customer') }}</dt><dd>{{ $response->customer_phone ?: '—' }}</dd></div>
                @endcan
                <div><dt>{{ __('feedback.survey') }}</dt><dd>{{ $response->version->survey->name }}</dd></div>
                <div><dt>{{ __('feedback.source') }}</dt><dd>{{ __('feedback.history_source_'.$response->source) }}</dd></div>
                <div><dt>{{ __('feedback.received_at') }}</dt><dd>{{ $response->received_at->setTimezone('Asia/Vientiane')->format('d/m/Y H:i') }}</dd></div>
                <div><dt>{{ __('feedback.submitted_at') }}</dt><dd>{{ $response->submitted_at->setTimezone('Asia/Vientiane')->format('d/m/Y H:i') }}</dd></div>
                @if ($response->context)<div><dt>{{ __('feedback.transaction_type') }}</dt><dd>{{ $response->context->transaction_type }}</dd></div>@endif
            </dl>
        </div>
        @can('customer_identity.read')
            @if (filled($response->customer_name) || filled($response->customer_phone))
                <p class="eg-customer-filter-hint">{{ __('feedback.customer_app_reported_profile') }}</p>
            @endif
        @endcan
    </section>
    @foreach ($response->answers as $answer)
        <section class="eg-customer-detail-card">
            <h2>{{ $answer->question->label[app()->getLocale()] ?? $answer->question->label['en'] }}</h2>
            @if ($answer->rating_value !== null)
                <p class="eg-customer-detail-label">{{ __('feedback.rating') }}</p>
                <span class="eg-customer-stars" role="img" aria-label="{{ $answer->rating_value }} / 5">
                    @for ($star = 1; $star <= 5; $star++)<span @class(['eg-customer-star-empty' => $star > $answer->rating_value]) aria-hidden="true">★</span>@endfor
                </span>
            @endif
            @if (filled($answer->text_value))<div class="eg-customer-comment-box">{{ $answer->text_value }}</div>@endif
            @if ($answer->options->isNotEmpty())
                <p class="eg-customer-detail-label">{{ __('feedback.customer_selected_answers') }}</p>
                <div class="eg-customer-answer-grid">
                    @foreach ($answer->options as $option)<div class="eg-customer-answer-box">{{ $option->label[app()->getLocale()] ?? $option->label['en'] }}</div>@endforeach
                </div>
            @endif
        </section>
    @endforeach
</div>
