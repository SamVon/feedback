@php
    $labels = $getRecord()->answers->map(fn ($answer) => $answer->question->label[app()->getLocale()] ?? $answer->question->label['en'])->unique();
@endphp
<div class="eg-feedback-question-cell">
    @forelse ($labels as $label)
        <p class="eg-feedback-truncate" title="{{ $label }}">{{ $label }}</p>
    @empty
        <span>—</span>
    @endforelse
</div>
