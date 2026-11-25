<x-filament-panels::page>
    @php
        $summary = $this->summary();
        $colors = [5 => '#14b8a6', 4 => '#3b82f6', 3 => '#ec4899', 2 => '#f59e0b', 1 => '#facc15'];
        $stops = [];
        $offset = 0;
        foreach ([5, 4, 3, 2, 1] as $rating) {
            $end = $offset + ($summary['rating_count'] ? 100 * $summary['distribution'][$rating] / $summary['rating_count'] : 0);
            $stops[] = $colors[$rating].' '.$offset.'% '.$end.'%';
            $offset = $end;
        }
        $choices = $this->choiceSummary();
        $recent = $this->recentFeedback();
    @endphp
    <div class="eg-dashboard">
        @include('filament.partials.feedback-tabs', ['active' => 'dashboard'])
        <section class="eg-dashboard-overview">
            {{ $this->filtersForm }}
            <div class="eg-dashboard-summary">
                <div class="eg-dashboard-stat-stack">
                    <article class="eg-dashboard-card eg-dashboard-stat">
                        <div class="eg-dashboard-card-heading"><h2>{{ __('feedback.average') }}</h2><span class="eg-dashboard-icon"><x-filament::icon icon="heroicon-s-star" /></span></div>
                        <p class="eg-dashboard-number">{{ $summary['average'] === null ? __('feedback.no_data') : number_format($summary['average'], 2).' / 5.0' }}</p>
                        <p class="eg-dashboard-muted">{{ number_format($summary['rating_count']) }} {{ __('feedback.ratings') }}</p>
                        @isset($summary['rating_question_label'])
                            <p class="eg-dashboard-muted">{{ $summary['rating_question_survey'] }} · {{ $summary['rating_question_label'] }}</p>
                        @endisset
                    </article>
                    <article class="eg-dashboard-card eg-dashboard-stat">
                        <div class="eg-dashboard-card-heading"><h2>{{ __('feedback.csat_score') }}</h2><span class="eg-dashboard-icon"><x-filament::icon icon="heroicon-o-chart-bar" /></span></div>
                        <p class="eg-dashboard-number">{{ $summary['csat'] === null ? __('feedback.no_data') : number_format($summary['csat'], 1).'%' }}</p>
                        <p class="eg-dashboard-muted">{{ __('feedback.csat_hint') }}</p>
                    </article>
                </div>
                <article class="eg-dashboard-card">
                    <div class="eg-dashboard-card-heading"><h2>{{ __('feedback.responses') }}</h2><span class="eg-dashboard-icon"><x-filament::icon icon="heroicon-o-users" /></span></div>
                    <div class="eg-dashboard-responders">
                        <div class="eg-dashboard-ring eg-dashboard-response-ring"><div><strong>{{ number_format($summary['response_count']) }}</strong><span>{{ __('feedback.responses') }}</span></div></div>
                        <dl class="eg-dashboard-totals">
                            <div><dt>{{ __('feedback.responses') }}</dt><dd>{{ number_format($summary['response_count']) }}</dd></div>
                            <div><dt>{{ __('feedback.unique_customers') }}</dt><dd>{{ number_format($summary['unique_customers']) }}</dd></div>
                            <div><dt>{{ __('feedback.ratings') }}</dt><dd>{{ number_format($summary['rating_count']) }}</dd></div>
                        </dl>
                    </div>
                    <p class="eg-dashboard-muted">{{ __('feedback.dashboard_accounts_hint') }}</p>
                </article>
            </div>
        </section>
        <div class="eg-dashboard-grid">
            <section class="eg-dashboard-card">
                <h2>{{ __('feedback.distribution') }}</h2>
                <div class="eg-dashboard-distribution">
                    <div class="eg-dashboard-ring" style="background: {{ $summary['rating_count'] ? 'conic-gradient('.implode(', ', $stops).')' : '#edf0f3' }}"><div><span>{{ __('feedback.ratings') }}</span><strong>{{ number_format($summary['rating_count']) }}</strong></div></div>
                    <table class="eg-dashboard-table">
                        <thead><tr><th>{{ __('feedback.rating') }}</th><th>{{ __('feedback.responses') }}</th><th>%</th></tr></thead>
                        <tbody>@foreach([5, 4, 3, 2, 1] as $rating)
                            <tr><th><span class="eg-dashboard-dot" style="background: {{ $colors[$rating] }}"></span>{{ $rating }} ★</th><td>{{ number_format($summary['distribution'][$rating]) }}</td><td>{{ $summary['rating_count'] ? number_format(100 * $summary['distribution'][$rating] / $summary['rating_count'], 1).'%' : '—' }}</td></tr>
                        @endforeach</tbody>
                    </table>
                </div>
                @if(! $summary['rating_count'])<p class="eg-dashboard-muted">{{ __('feedback.question_hint') }}</p>@endif
            </section>
            <div class="eg-dashboard-chart">
                @livewire(\App\Filament\Widgets\Trend::class, ['pageFilters' => $this->dataFilters()], key('dashboard-trend'))
            </div>
            <section class="eg-dashboard-card">
                <h2>{{ __('feedback.choices') }}</h2>
                @isset($choices['question'])<p class="eg-dashboard-muted">{{ $choices['question'] }}</p>@endisset
                <p class="eg-dashboard-muted">{{ __('feedback.choice_denominator_hint') }}</p>
                <div class="eg-dashboard-choice-list">
                    @forelse(($choices['options'] ?? []) as $choice)
                        <div><div class="eg-dashboard-choice-label"><span>{{ $choice['label'] }}</span><strong>{{ $choice['percent'] === null ? '—' : number_format($choice['percent'], 1).'%' }} ({{ number_format($choice['count']) }})</strong></div>
                        <div class="eg-dashboard-progress" role="progressbar" aria-label="{{ $choice['label'] }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $choice['percent'] ?? 0 }}"><span style="width: {{ $choice['percent'] ?? 0 }}%"></span></div></div>
                    @empty<p class="eg-dashboard-muted">{{ __('feedback.no_data') }}</p>@endforelse
                </div>
            </section>
            @can('feedback.read')
                <section class="eg-dashboard-card">
                    <h2>{{ __('feedback.latest') }} · Text Feedback</h2>
                    <div class="eg-dashboard-feedback-list">
                        @forelse($recent as $response)
                            <article class="eg-dashboard-feedback">
                                <div class="eg-dashboard-feedback-content">
                                    <div class="eg-dashboard-feedback-heading"><strong>{{ $response->actor_user_id }}</strong></div>
                                    @foreach($response->answers->filter(fn ($answer) => filled($answer->text_value)) as $answer)
                                        <p>“{{ $answer->text_value }}”</p>
                                    @endforeach
                                    <time datetime="{{ $response->received_at->toIso8601String() }}">{{ $response->received_at->setTimezone('Asia/Vientiane')->format('d/m/Y H:i') }}</time>
                                </div>
                                @php($ratingAnswer = isset($this->filters['question_id']) ? $response->answers->firstWhere('question_id', $this->filters['question_id']) : $response->answers->firstWhere('rating_value', '!=', null))
                                @if($ratingAnswer?->rating_value)
                                    <span class="eg-dashboard-stars" role="img" aria-label="{{ $ratingAnswer->rating_value }} / 5">
                                        @for($star = 1; $star <= 5; $star++)
                                            <x-filament::icon icon="heroicon-s-star" @class(['eg-dashboard-star-empty' => $star > $ratingAnswer->rating_value]) />
                                        @endfor
                                    </span>
                                @endif
                            </article>
                        @empty<p class="eg-dashboard-muted">{{ __('feedback.no_data') }}</p>@endforelse
                    </div>
                </section>
            @endcan
        </div>
    </div>
</x-filament-panels::page>
