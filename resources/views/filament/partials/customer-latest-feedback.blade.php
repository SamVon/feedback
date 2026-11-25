@php
    $response = $getRecord() instanceof \App\Models\FeedbackResponse ? $getRecord() : $getRecord()->latestFilteredResponse;
@endphp
<div class="eg-customer-feedback-cell">
    @if ($response)
        @if ($response->answers->contains(fn ($answer) => filled($answer->text_value) || $answer->options->isNotEmpty()))
        <ul>
            @foreach ($response->answers as $answer)
                @if (filled($answer->text_value))<li>{{ $answer->text_value }}</li>@endif
                @foreach ($answer->options as $option)<li>{{ $option->label[app()->getLocale()] ?? $option->label['en'] }}</li>@endforeach
            @endforeach
        </ul>
        @else
            <span>—</span>
        @endif
    @else
        <span>—</span>
    @endif
</div>
