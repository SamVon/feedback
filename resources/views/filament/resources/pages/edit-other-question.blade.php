<x-filament-panels::page>
    <form wire:submit="save" class="eg-other-question-editor">
        <section class="eg-editor-card">
            <h1>{{ __($this->questionId ? 'feedback.other_question_edit_heading' : 'feedback.other_question_create_heading') }}</h1>
            @if ($surveyLabel = $this->selectedSurveyLabel())
                <p class="eg-other-question-context">{{ __('feedback.survey') }}: {{ $surveyLabel }}</p>
            @else
                <p class="eg-other-question-context">{{ __('feedback.other_question_no_survey') }}</p>
            @endif
            <p class="eg-other-question-language-hint" role="note">{{ __('feedback.other_question_pair_hint') }}</p>
            @if ($errors->any())
                <div class="eg-editor-errors" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            {{ $this->form }}
        </section>
        <footer class="eg-editor-footer">
            <p class="eg-other-question-save-hint">{{ __('feedback.question_publish_hint') }}</p>
            <x-filament::button tag="a" :href="\App\Filament\Resources\OtherQuestionResource::getUrl('index')" color="gray" outlined>{{ __('feedback.back') }}</x-filament::button>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('feedback.other_question_save') }}</x-filament::button>
        </footer>
    </form>
</x-filament-panels::page>
