<x-filament-panels::page>
    <div class="eg-rating-editor" x-data x-on:rating-step-changed.window="$nextTick(() => { window.scrollTo({ top: 0, behavior: 'instant' }); $el.querySelector('h2')?.focus({ preventScroll: true }); })">
        @if ($errors->any())
            <div class="eg-editor-errors" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if ($this->editingScore === null)
            <form wire:submit="save">
                <section class="eg-editor-card">
                    <h2 tabindex="-1">{{ __('feedback.rating_setup') }}</h2>
                    <p class="eg-editor-hint">{{ __('feedback.rating_page_hint') }}</p>
                    @if (count($this->questionLinks) > 1)
                        <nav class="eg-editor-question-links" aria-label="{{ __('feedback.questions') }}">
                            @foreach ($this->questionLinks as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach
                        </nav>
                    @endif
                    {{ $this->questionForm }}
                </section>
                <section class="eg-editor-card">
                    <h2 tabindex="-1">{{ __('feedback.rating_answer_sets') }}</h2>
                    <p class="eg-editor-hint">{{ __('feedback.rating_five_levels') }}</p>
                    <div class="eg-editor-table-scroll"><table class="eg-editor-table">
                        <thead><tr><th>#</th><th>{{ __('feedback.rating') }}</th><th>{{ __('feedback.answers') }}</th><th>{{ __('feedback.edit_question') }}</th></tr></thead>
                        <tbody>
                        @foreach (range(5, 1) as $score)
                            <tr wire:key="rating-set-{{ $score }}">
                                <td>{{ 6 - $score }}</td><th scope="row">{{ __('feedback.stars_count', ['count' => $score]) }}</th>
                                <td><ul>@forelse ($this->reasons[$score] ?? [] as $option)<li>{{ $option['label'][app()->getLocale()] ?? $option['label']['en'] ?? '' }}</li>@empty<li class="eg-editor-hint">{{ __('feedback.rating_no_options') }}</li>@endforelse</ul></td>
                                <td><x-filament::button type="button" color="primary" outlined icon="heroicon-o-pencil-square" wire:click="editReasons({{ $score }})" wire:loading.attr="disabled" aria-label="{{ __('feedback.reasons_for_rating', ['count' => $score]) }}">{{ __('feedback.edit_question') }}</x-filament::button></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    <footer class="eg-editor-footer">
                        <x-filament::button tag="a" href="{{ \App\Filament\Resources\RatingQuestionResource::getUrl() }}" color="gray">{{ __('feedback.back') }}</x-filament::button>
                        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('feedback.rating_save_draft') }}</x-filament::button>
                    </footer>
                </section>
            </form>
        @else
            <form wire:submit="applyReasons" class="eg-editor-card" wire:key="reason-editor-{{ $this->editingScore }}">
                <h2 tabindex="-1">{{ __('feedback.reasons_for_rating', ['count' => $this->editingScore]) }}</h2>
                <p class="eg-editor-hint">{{ __('feedback.rating_apply_hint') }}</p>
                {{ $this->reasonForm }}
                <footer class="eg-editor-footer">
                    <x-filament::button type="button" color="gray" wire:click="cancelReasons" wire:loading.attr="disabled">{{ __('feedback.back') }}</x-filament::button>
                    <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('feedback.rating_apply_options') }}</x-filament::button>
                </footer>
            </form>
        @endif
    </div>
</x-filament-panels::page>
