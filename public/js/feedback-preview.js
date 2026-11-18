document.addEventListener('alpine:init', () => {
    Alpine.data('feedbackPreview', (questions, locale, source = 'always_on') => ({
        source,
        get visibleQuestions() { return this.questions.filter(question => this.source !== 'auto_prompt' || question.type === 'rating'); },
        questions,
        locale,
        answers: {},
        submitted: false,
        init() { this.reset(); },
        reset() {
            this.answers = Object.fromEntries(this.questions.map(question => [question.id, {rating: null, selected: [], text: ''}]));
            this.submitted = false;
        },
        label(item) { return item.label[this.locale] || item.label.en; },
        setRating(question, score) {
            if (this.answers[question.id].rating !== score) {
                this.answers[question.id].rating = score;
                this.answers[question.id].selected = [];
            }
            this.submitted = false;
        },
        reasons(question) { return question.rating_reasons[this.answers[question.id].rating] || []; },
        toggle(question, id) {
            const answer = this.answers[question.id];
            if (answer.selected.includes(id)) {
                answer.selected = answer.selected.filter(value => value !== id);
            } else {
                const limit = question.max_choices;
                const options = question.type === 'rating' ? this.reasons(question) : question.options;
                if (answer.selected.length < limit && options.some(option => option.id === id)) answer.selected.push(id);
            }
            this.submitted = false;
        },
        validQuestion(question) {
            const answer = this.answers[question.id];
            if (question.type === 'rating') {
                if (answer.rating === null) return !question.required && !answer.text.trim();
                if (question.comment?.channels.includes(this.source) && Array.from(answer.text).length > question.comment.max_length) return false;
                return !question.rating_reasons_enabled || !question.rating_reasons_required || answer.selected.length > 0;
            }
            if (question.type === 'text') return !question.required || answer.text.trim().length > 0;
            return !question.required || answer.selected.length > 0;
        },
        valid() { return this.visibleQuestions.every(question => this.validQuestion(question)); },
    }));
});
