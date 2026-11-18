@php
    $previewQuestions = $version->questions->where('enabled', true)->map(fn ($question) => \App\Application\SurveyPresentation::question($question))->values();
@endphp
<div class="eg-mobile-preview" x-data="feedbackPreview(@js($previewQuestions), @js(app()->getLocale()), @js($source ?? 'always_on'))">
    <p class="eg-preview-notice">{{ __('feedback.preview_only') }} · v{{ $version->version }}</p>
    <label for="preview-source">{{ __('feedback.preview_channel') }}</label>
    <select id="preview-source" x-model="source" @change="reset()" style="width:100%; margin-bottom:1rem; padding:0.5rem">
        <option value="auto_prompt">{{ __('feedback.popup_rating_only') }}</option>
        <option value="always_on">{{ __('feedback.feedback_all_questions') }}</option>
    </select>
    <div class="eg-feedback-popup">
        <header class="eg-popup-heading">
            <x-filament::icon icon="heroicon-o-chat-bubble-left-ellipsis" />
            <h2>{{ \App\Application\ChannelPolicy::content($version)['title'][app()->getLocale()] }}</h2>
            <p>{{ \App\Application\ChannelPolicy::content($version)['intro'][app()->getLocale()] }}</p>
        </header>
        <h3 class="eg-popup-section-title">{{ $version->survey->name }}</h3>
        <template x-for="question in visibleQuestions" :key="question.id">
            <section class="eg-popup-question">
                <h4><span x-text="label(question)"></span><span x-show="question.required" aria-hidden="true"> *</span></h4>
                <template x-if="question.type === 'rating'">
                    <div>
                        <fieldset class="eg-popup-stars" :aria-label="label(question)">
                            <template x-for="score in [1, 2, 3, 4, 5]" :key="score">
                                <label class="eg-star-choice">
                                    <input type="radio" :name="'preview-rating-' + question.id" :value="score" :checked="answers[question.id].rating === score" @change="setRating(question, score)" :aria-label="score + ' / 5'">
                                    <span class="eg-star-symbol" :class="{'is-selected': answers[question.id].rating >= score}" aria-hidden="true" x-text="answers[question.id].rating >= score ? '★' : '☆'"></span>
                                    <span x-text="score"></span>
                                </label>
                            </template>
                        </fieldset>
                        <template x-if="question.rating_reasons_enabled">
                            <div class="eg-popup-reasons">
                                <h4>{{ __('feedback.reason_prompt') }}</h4>
                                <p class="eg-popup-hint" x-show="! answers[question.id].rating">{{ __('feedback.choose_rating_first') }}</p>
                                <div class="eg-reason-grid">
                                    <template x-for="option in reasons(question)" :key="option.id">
                                        <button type="button" :class="{'is-selected': answers[question.id].selected.includes(option.id)}" :aria-pressed="answers[question.id].selected.includes(option.id)" :disabled="!answers[question.id].selected.includes(option.id) && answers[question.id].selected.length >= question.max_choices" @click="toggle(question, option.id)" x-text="label(option)"></button>
                                    </template>
                                </div>
                                <p class="eg-popup-hint"><span>{{ __('feedback.choose_up_to') }}</span> <span x-text="question.max_choices"></span> <span>{{ __('feedback.items') }}</span><span x-show="question.rating_reasons_required"> · {{ __('feedback.answer_required') }}</span></p>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="question.type === 'rating' && question.comment?.channels.includes(source)">
                    <div class="eg-popup-comment">
                        <h4><label :for="'preview-comment-' + question.id" x-text="label(question.comment)"></label></h4>
                        <textarea :id="'preview-comment-' + question.id" x-model="answers[question.id].text" :maxlength="question.comment.max_length" :aria-label="label(question.comment)" placeholder="{{ __('feedback.comment_placeholder') }}"></textarea>
                    </div>
                </template>
                <template x-if="question.type === 'choice'">
                    <div class="eg-reason-grid">
                        <template x-for="option in question.options" :key="option.id">
                            <button type="button" :class="{'is-selected': answers[question.id].selected.includes(option.id)}" :aria-pressed="answers[question.id].selected.includes(option.id)" :disabled="!answers[question.id].selected.includes(option.id) && answers[question.id].selected.length >= question.max_choices" @click="toggle(question, option.id)" x-text="label(option)"></button>
                        </template>
                    </div>
                </template>
                <template x-if="question.type === 'text'">
                    <textarea x-model="answers[question.id].text" :maxlength="question.max_length" :aria-label="label(question)" placeholder="{{ __('feedback.comment_placeholder') }}"></textarea>
                </template>
                <p class="eg-popup-error" x-show="submitted && !validQuestion(question)" role="alert">{{ __('feedback.preview_answer_error') }}</p>
            </section>
        </template>
        <p class="eg-preview-success" x-show="submitted && valid()" x-cloak role="status">{{ __('feedback.preview_valid') }}</p>
        <footer class="eg-popup-actions">
            <button type="button" @click="reset()">{{ __('feedback.reset_preview') }}</button>
            <button type="button" @click="submitted = true">{{ __('feedback.preview_submit') }}</button>
        </footer>
        <p class="eg-popup-footnote">{{ __('feedback.preview_only') }}</p>
    </div>
</div>
