<x-filament-panels::page>
    @php
        $dailyTab = $this->showsDailyLimit();
        $dailyLimit = $dailyTab ? \App\Models\DailyFeedbackLimit::current() : null;
        $published = $dailyTab ? null : $this->publishedRule();
        $ruleResource = \App\Filament\Resources\RuleResource::class;
        $state = $published ? $ruleResource::activeState($published->rules) : null;
    @endphp

    <div class="eg-rules-page">
        <nav class="eg-dashboard-tabs" aria-label="{{ __('feedback.manage_rules') }}">
            <a href="{{ $ruleResource::getUrl('index') }}" @if (! $dailyTab) aria-current="page" @endif>{{ __('feedback.rules_popup_tab') }}</a>
            <a href="{{ $ruleResource::getUrl('index', ['tab' => 'daily-limit']) }}" @if ($dailyTab) aria-current="page" @endif>{{ __('feedback.rules_daily_tab') }}</a>
        </nav>

        <section class="eg-rules-summary" aria-labelledby="rules-summary-heading">
            <div class="eg-rules-summary-header">
                <h1 id="rules-summary-heading">{{ __('feedback.rules_configuration') }}</h1>
                @if ($dailyTab)
                    {{ $this->editDailyLimitAction }}
                @elseif ($published)
                    @can('rules.edit')
                        {{ ($this->editPublishedAction)(['rule' => $published->id]) }}
                    @endcan
                @endif
            </div>

            @if ($dailyTab)
                <dl class="eg-rules-overview eg-rules-overview-daily">
                    <div>
                        <x-filament::icon icon="heroicon-o-chart-bar-square" />
                        <div>
                            <dt>{{ __('feedback.daily_limit') }}</dt>
                            <dd>{{ $dailyLimit ? __('feedback.daily_limit_count', ['count' => $dailyLimit->response_limit]) : __('feedback.daily_limit_unset') }}</dd>
                        </div>
                    </div>
                </dl>
            @elseif ($published)
                <dl class="eg-rules-overview">
                    <div>
                        <x-filament::icon icon="heroicon-o-clock" />
                        <div>
                            <dt>{{ __('feedback.popup_schedule') }}</dt>
                            <dd>
                                {{ $ruleResource::scheduleLabel($published->rules) }}
                                @if (($duration = $ruleResource::durationLabel($published->rules)) !== '—')
                                    ({{ $duration }})
                                @endif
                            </dd>
                        </div>
                    </div>
                    <div>
                        <x-filament::icon :icon="$state === 'on' ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed'" />
                        <div>
                            <dt>{{ __('feedback.status') }}</dt>
                            <dd>
                                <x-filament::badge :color="match ($state) { 'on' => 'success', 'scheduled' => 'warning', default => 'gray' }">
                                    {{ __('feedback.rules_states.'.$state) }}
                                </x-filament::badge>
                            </dd>
                        </div>
                    </div>
                    <div>
                        <x-filament::icon icon="heroicon-o-hand-raised" />
                        <div>
                            <dt>{{ __('feedback.transaction_type') }}</dt>
                            <dd>{{ $ruleResource::transactionLabels($published->rules) ?: '—' }}</dd>
                        </div>
                    </div>
                </dl>
            @else
                <p class="eg-rules-empty">{{ __('feedback.rules_no_published') }}</p>
            @endif
        </section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
