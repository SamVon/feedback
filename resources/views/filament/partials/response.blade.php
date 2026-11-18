<div class="eg-detail">
    <p><strong>{{ $response->actor_user_id }}</strong> · {{ $response->source }}</p>
    <p>{{ __('feedback.received_at') }}: {{ $response->received_at->setTimezone('Asia/Vientiane')->format('d M Y H:i:s') }} · Asia/Vientiane</p>
    <p>{{ __('feedback.submitted_at') }}: {{ $response->submitted_at->setTimezone('Asia/Vientiane')->format('d M Y H:i:s') }}</p>
    @foreach($response->answers as $answer)
    <section><h3>{{ $answer->question->label[app()->getLocale()] ?? $answer->question->label['en'] }}</h3>
    @if($answer->rating_value !== null)<p class="eg-stars">{{ str_repeat('★', $answer->rating_value) }}{{ str_repeat('☆', 5 - $answer->rating_value) }} · {{ $answer->rating_value }}/5</p>@endif
    @if($answer->text_value !== null)<p style="white-space:pre-wrap">{{ $answer->text_value }}</p>@endif
    @foreach($answer->options as $option)<p>{{ $option->label[app()->getLocale()] ?? $option->label['en'] }}</p>@endforeach
    </section>
    @endforeach
</div>