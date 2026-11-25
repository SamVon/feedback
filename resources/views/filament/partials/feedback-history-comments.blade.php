@php
    $comments = $getRecord()->answers->filter(fn ($answer) => filled($answer->text_value));
@endphp
<div class="eg-feedback-comment-cell">
    @forelse ($comments as $answer)
        <p class="eg-feedback-truncate" title="{{ $answer->text_value }}">{{ $answer->text_value }}</p>
    @empty
        <span>—</span>
    @endforelse
</div>
