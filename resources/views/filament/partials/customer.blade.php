<div class="eg-detail">
<h2>{{ $detail['display_name'] ?? $detail['actor_user_id'] }}</h2>
@if(isset($detail['phone']))<p>{{ $detail['phone'] }}</p>@endif
<p>{{ __('feedback.responses') }}: {{ $detail['response_count'] }} · {{ __('feedback.'.$detail['response_status']) }} · {{ __('feedback.'.$detail['invitation_status']) }}</p>
@if($detail['metrics'])<p>{{ __('feedback.average') }}: {{ $detail['metrics']['average'] ?? __('feedback.no_data') }} · CSAT: {{ $detail['metrics']['csat'] === null ? __('feedback.no_data') : $detail['metrics']['csat'].'%' }}</p>@endif
<p>{{ __('feedback.history_hint') }}</p>
@forelse($history as $response) @include('filament.partials.response') @empty <p>{{ __('feedback.empty') }}</p> @endforelse
</div>