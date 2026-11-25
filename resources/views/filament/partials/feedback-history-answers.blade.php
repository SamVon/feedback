@php
    $choiceAnswers = $getRecord()->answers->filter(fn ($answer) => $answer->options->isNotEmpty());
@endphp
<div class="eg-feedback-answer-cell">
    @if ($choiceAnswers->isNotEmpty())
        <ul>
            @foreach ($choiceAnswers as $answer)
                @foreach ($answer->options as $option)
                    @php($label = $option->label[app()->getLocale()] ?? $option->label['en'])
                    <li title="{{ $answer->question->label[app()->getLocale()] ?? $answer->question->label['en'] }} · {{ $label }}"><span class="eg-feedback-truncate">{{ $label }}</span></li>
                @endforeach
            @endforeach
        </ul>
    @else
        <span>—</span>
    @endif
</div>
