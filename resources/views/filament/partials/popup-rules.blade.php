@php
    $schedule = \App\Application\ChannelPolicy::schedule($rules);
    $enabled = (bool) ($rules['channels']['auto_prompt'] ?? false);
    $transactionNames = __('feedback.rules_transaction_names');
@endphp
<div class="eg-rule-detail">
    <section class="eg-rule-detail-period" aria-labelledby="rule-detail-period-heading">
        <div class="eg-rule-detail-heading">
            <h3 id="rule-detail-period-heading">{{ __('feedback.rules_form_period') }}</h3>
            <x-filament::badge :color="$enabled ? 'success' : 'gray'">Pop-up · {{ __('feedback.rules_states.'.($enabled ? 'on' : 'off')) }}</x-filament::badge>
        </div>
        <dl class="eg-rule-detail-dates">
            @foreach ($schedule as $boundary => $value)
                <div>
                    <dt>{{ __('feedback.'.$boundary) }}</dt>
                    <dd>{{ $value ? \Carbon\CarbonImmutable::parse($value)->setTimezone('Asia/Vientiane')->format('d/m/Y H:i') : __('feedback.rules_detail_unset') }}</dd>
                </div>
            @endforeach
            <div>
                <dt>{{ __('feedback.rules_duration') }}</dt>
                <dd>{{ \App\Filament\Resources\RuleResource::durationLabel($rules) }}</dd>
            </div>
        </dl>
    </section>

    <section aria-labelledby="rule-detail-transactions-heading">
        <h3 id="rule-detail-transactions-heading">{{ __('feedback.rules_form_transactions') }}</h3>
        <div class="eg-rule-detail-transactions">
            @forelse ($rules['transaction_rules'] as $rule)
                <article class="eg-rule-detail-transaction">
                    <div class="eg-rule-detail-transaction-heading">
                        <h4>{{ $transactionNames[$rule['transaction_type']] ?? $rule['transaction_type'] }}</h4>
                        <x-filament::badge :color="$rule['enabled'] ? 'success' : 'gray'">{{ __('feedback.rules_states.'.($rule['enabled'] ? 'on' : 'off')) }}</x-filament::badge>
                    </div>
                    <dl class="eg-rule-detail-limits">
                        <div>
                            <dt>{{ __('feedback.rules_detail_limit') }}</dt>
                            <dd>{{ __('feedback.rules_detail_count', ['count' => $rule['max_responses']]) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('feedback.rules_detail_cooldown') }}</dt>
                            <dd>{{ __('feedback.rules_days', ['count' => $rule['cooldown_days']]) }}</dd>
                        </div>
                    </dl>
                </article>
            @empty
                <p class="eg-rule-detail-empty">{{ __('feedback.no_data') }}</p>
            @endforelse
        </div>
        <p class="eg-rule-detail-note">{{ __('feedback.rules_detail_note') }}</p>
    </section>

    <details class="eg-rule-detail-advanced">
        <summary>{{ __('feedback.rules_advanced') }}</summary>
        <dl class="eg-rule-detail-limits">
            <div><dt>{{ __('feedback.submission_rate_limit') }}</dt><dd>{{ $rules['rate_limit'] }}</dd></div>
            <div><dt>{{ __('feedback.submission_rate_window') }}</dt><dd>{{ $rules['rate_window_seconds'] }}</dd></div>
            <div><dt>{{ __('feedback.invitation_lifetime') }}</dt><dd>{{ $rules['invitation_ttl_seconds'] }}</dd></div>
        </dl>
    </details>
</div>
