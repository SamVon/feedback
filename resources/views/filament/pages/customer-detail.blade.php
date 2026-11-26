<x-filament-panels::page>
    @php
        $customer = $this->customer;
        $responses = $this->responses;
        $response = $responses->first();
        $missingProfile = ! filled($customer->display_name) && ! filled($customer->phone);
        $ratings = $response?->answers->filter(fn ($answer) => $answer->question->type === 'rating') ?? collect();
        $choices = $response?->answers->filter(fn ($answer) => $answer->question->type === 'choice') ?? collect();
        $comments = $response?->answers->filter(fn ($answer) => filled($answer->text_value)) ?? collect();
    @endphp
    <div class="eg-customer-detail-page">
        <nav class="eg-customer-detail-breadcrumb" aria-label="{{ __('feedback.customers') }}">
            <a href="{{ \App\Filament\Pages\Customers::getUrl(['filters' => $this->filters, 'customer_source' => $this->customerSource]) }}">{{ __('feedback.customers') }}</a>
            <span aria-hidden="true">›</span>
            <span>{{ __('feedback.customer_detail_title') }}</span>
        </nav>
        <section class="eg-customer-detail-card" aria-labelledby="customer-detail-title">
            <div class="eg-customer-detail-title">
                <h1 id="customer-detail-title">{{ __('feedback.customer_detail_title') }}</h1>
                <x-filament::button tag="a" :href="\App\Filament\Pages\Customers::getUrl(['filters' => $this->filters, 'customer_source' => $this->customerSource])" outlined color="gray" size="sm">{{ __('feedback.back') }}</x-filament::button>
            </div>
            <div class="eg-customer-detail-profile">
                <div class="eg-customer-detail-avatar"><img src="{{ asset('images/easy-gold-mark.svg') }}" alt="Easy Gold" /></div>
                <dl>
                    <div><dt>{{ __('feedback.customer_user_id') }}</dt><dd>{{ $customer->actor_user_id }}</dd></div>
                    @can('customer_identity.read')
                        <div><dt>{{ __('feedback.name') }}</dt><dd>{{ $customer->display_name ?: '—' }}</dd></div>
                        <div><dt>{{ __('feedback.customer') }}</dt><dd>{{ $customer->phone ?: '—' }}</dd></div>
                        @if ($response && filled($response->customer_name) && $response->customer_name !== $customer->display_name)
                            <div><dt>{{ __('feedback.customer_submitted_name') }}</dt><dd>{{ $response->customer_name }}</dd></div>
                        @endif
                        @if ($response && filled($response->customer_phone) && $response->customer_phone !== $customer->phone)
                            <div><dt>{{ __('feedback.customer_submitted_phone') }}</dt><dd>{{ $response->customer_phone }}</dd></div>
                        @endif
                    @endcan
                    @if ($response)
                        <div><dt>{{ __('feedback.received_at') }}</dt><dd>{{ $response->received_at->setTimezone('Asia/Vientiane')->format('d/m/Y H:i') }}</dd></div>
                        <div><dt>{{ __('feedback.survey') }}</dt><dd>{{ $response->version->survey->name }}</dd></div>
                    @endif
                </dl>
            </div>
            @can('customer_identity.read')
                @if (($customer->app_reported_name && filled($customer->display_name)) || ($customer->app_reported_phone && filled($customer->phone)))
                    <p class="eg-customer-filter-hint">{{ __('feedback.customer_app_reported_profile') }}</p>
                @endif
            @endcan
            @if ($missingProfile)
                <p class="eg-customer-warning">{{ __('feedback.customer_profile_pending') }}</p>
            @endif
        </section>
        @if ($response)
            @if ($ratings->isNotEmpty())
                <section class="eg-customer-detail-card" aria-labelledby="customer-rating-title">
                    <h2 id="customer-rating-title">{{ __('feedback.customer_rating_answers') }}</h2>
                    @foreach ($ratings as $answer)
                        <article class="eg-customer-detail-question">
                            <h3>{{ $answer->question->label[app()->getLocale()] ?? $answer->question->label['en'] }}</h3>
                            <p class="eg-customer-detail-label">{{ __('feedback.rating') }}</p>
                            <span class="eg-customer-stars" role="img" aria-label="{{ $answer->rating_value }} / 5">
                                @for ($star = 1; $star <= 5; $star++)<span @class(['eg-customer-star-empty' => $star > $answer->rating_value]) aria-hidden="true">★</span>@endfor
                            </span>
                            @if ($answer->options->isNotEmpty())
                                <p class="eg-customer-detail-label">{{ __('feedback.customer_selected_answers') }}</p>
                                <div class="eg-customer-answer-grid">
                                    @foreach ($answer->options as $option)<div class="eg-customer-answer-box">{{ $option->label[app()->getLocale()] ?? $option->label['en'] }}</div>@endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </section>
            @endif
            @if ($choices->isNotEmpty())
                <section class="eg-customer-detail-card" aria-labelledby="customer-other-title">
                    <h2 id="customer-other-title">{{ __('feedback.customer_other_answers') }}</h2>
                    @foreach ($choices as $answer)
                        <article class="eg-customer-detail-question">
                            <h3>{{ $answer->question->label[app()->getLocale()] ?? $answer->question->label['en'] }}</h3>
                            <p class="eg-customer-detail-label">{{ __('feedback.customer_selected_answers') }}</p>
                            <div class="eg-customer-answer-grid">
                                @foreach ($answer->options as $option)<div class="eg-customer-answer-box">{{ $option->label[app()->getLocale()] ?? $option->label['en'] }}</div>@endforeach
                            </div>
                        </article>
                    @endforeach
                </section>
            @endif
            @if ($comments->isNotEmpty())
                <section class="eg-customer-detail-card" aria-labelledby="customer-comments-title">
                    <h2 id="customer-comments-title">{{ __('feedback.customer_additional_feedback') }}</h2>
                    @foreach ($comments as $answer)
                        <div class="eg-customer-detail-question">
                            <h3>{{ $answer->question->label[app()->getLocale()] ?? $answer->question->label['en'] }}</h3>
                            <div class="eg-customer-comment-box">{{ $answer->text_value }}</div>
                        </div>
                    @endforeach
                </section>
            @endif
            @if ($responses->hasPages())
                <div class="eg-customer-response-pagination">
                    <x-filament::pagination :paginator="$responses" />
                </div>
            @endif
        @else
            <section class="eg-customer-detail-card"><p>{{ __('feedback.customer_no_response') }}</p></section>
        @endif
    </div>
</x-filament-panels::page>
