<div class="eg-detail">
    <h3>{{ __('feedback.popup_schedule') }}</h3>
    <p>{{ __('feedback.schedule_hint') }}</p>
    @foreach(\App\Application\ChannelPolicy::schedule($rules) as $boundary => $value)
        <p>{{ __('feedback.'.$boundary) }}: {{ $value ? \Carbon\CarbonImmutable::parse($value)->setTimezone('Asia/Vientiane')->format('d/m/Y H:i') : '—' }}</p>
    @endforeach
    <p>{{ __('feedback.popup_policy_hint') }}</p>
    <p><strong>{{ __('feedback.popup_reset_hint') }}</strong></p>
    @if($legacy)<p>{{ __('feedback.popup_legacy_hint') }}</p>@endif
    @forelse($rules['transaction_rules'] as $rule)
        <section>
            <h3>{{ $rule['transaction_type'] }}</h3>
            <p>{{ __('feedback.popup_enabled') }}: {{ $rule['enabled'] ? __('feedback.popup_yes') : __('feedback.popup_no') }}</p>
            <p>{{ __('feedback.popup_max_responses') }}: {{ $rule['max_responses'] }}</p>
            <p>{{ __('feedback.popup_cooldown_days') }}: {{ $rule['cooldown_days'] }}</p>
            <p>{{ __('feedback.popup_reset_policy') }}: {{ __('feedback.popup_pending') }}</p>
        </section>
    @empty
        <p>{{ __('feedback.no_data') }}</p>
    @endforelse
    <dl>
        <dt>Auto prompt</dt><dd>{{ ($rules['channels']['auto_prompt'] ?? false) ? __('feedback.popup_yes') : __('feedback.popup_no') }}</dd>
        <dt>Always on</dt><dd>{{ ($rules['channels']['always_on'] ?? false) ? __('feedback.popup_yes') : __('feedback.popup_no') }}</dd>
        <dt>{{ __('feedback.submission_rate_limit') }}</dt><dd>{{ $rules['rate_limit'] }}</dd>
        <dt>{{ __('feedback.submission_rate_window') }}</dt><dd>{{ $rules['rate_window_seconds'] }}</dd>
        <dt>{{ __('feedback.invitation_lifetime') }}</dt><dd>{{ $rules['invitation_ttl_seconds'] }}</dd>
    </dl>
    <p>{{ __('feedback.popup_rate_hint') }}</p>
    <p>{{ __('feedback.popup_always_on_hint') }}</p>
</div>
