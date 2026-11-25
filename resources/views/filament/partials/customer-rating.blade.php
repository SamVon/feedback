@php
    $ratingResponse = $getRecord() instanceof \App\Models\FeedbackResponse ? $getRecord() : $getRecord()->latestFilteredResponse;
    $ratingAnswers = $ratingResponse?->answers->whereNotNull('rating_value')->values() ?? collect();
    if (! ($getRecord() instanceof \App\Models\FeedbackResponse)) {
        $ratingAnswers = $ratingAnswers->take(1);
    }
@endphp
@if ($ratingAnswers->isNotEmpty())
    <div class="eg-customer-rating-stack">
        @foreach ($ratingAnswers as $ratingAnswer)
            <span class="eg-customer-stars" role="img" aria-label="{{ $ratingAnswer->rating_value }} / 5" title="{{ $ratingAnswer->question->label[app()->getLocale()] ?? $ratingAnswer->question->label['en'] }}">
                @for ($star = 1; $star <= 5; $star++)
                    <span @class(['eg-customer-star-empty' => $star > $ratingAnswer->rating_value]) aria-hidden="true">★</span>
                @endfor
            </span>
        @endforeach
    </div>
@else
    <span>—</span>
@endif
