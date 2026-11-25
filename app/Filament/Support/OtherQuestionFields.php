<?php

namespace App\Filament\Support;

use App\Models\Question;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class OtherQuestionFields
{
    public static function make(bool $creating = false): array
    {
        $questionLimit = $creating ? 100 : 500;
        $answerLimit = $creating ? 100 : 200;

        return [
            Hidden::make('question.code')->default(fn () => 'q_'.Str::random(12)),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Group::make([
                    self::languagePair('question.label', __('feedback.question'), $questionLimit, __('feedback.other_question_placeholder')),
                    Toggle::make('question.enabled')->label(__('feedback.other_question_enable'))->default(false)
                        ->helperText(__('feedback.other_question_enabled_hint')),
                ])->extraAttributes(['class' => 'eg-other-question-prompt']),
                Group::make([
                    Repeater::make('question.options')->hiddenLabel()->defaultItems(1)
                        ->minItems(fn (Get $get) => $get('question.type') === 'choice' ? 1 : 0)->maxItems(50)
                        ->table([TableColumn::make(__('feedback.answers'))->hiddenHeaderLabel()])
                        ->addActionLabel(__('feedback.other_question_add_answer'))->addActionAlignment(Alignment::Start)
                        ->addAction(fn (Action $action) => $action->icon('heroicon-o-plus')->outlined()->color('danger'))
                        ->deleteAction(fn (Action $action) => $action->label(__('feedback.other_question_remove_answer'))->icon('heroicon-o-trash')->color('danger')->extraAttributes(['class' => 'eg-other-question-delete'], merge: true))
                        ->reorderAction(fn (Action $action) => $action->label(__('feedback.other_question_reorder_answers'))->color('gray')->extraAttributes(['class' => 'eg-other-question-reorder'], merge: true))
                        ->reorderableWithDragAndDrop()->reorderableWithButtons(false)
                        ->visible(fn (Get $get) => $get('question.type') === 'choice')->dehydratedWhenHidden()
                        ->dehydrateStateUsing(fn ($state, Get $get) => $get('question.type') === 'choice' ? array_values($state ?? []) : [])
                        ->schema([
                            Hidden::make('code')->default(fn () => Str::random(12)),
                            self::languagePair('label', __('feedback.other_question_answer'), $answerLimit, __('feedback.other_question_answer_placeholder')),
                        ])->extraAttributes(['class' => 'eg-other-question-answers']),
                    Text::make(__('feedback.other_question_text_hint'))->visible(fn (Get $get) => $get('question.type') === 'text'),
                ])->extraAttributes(['class' => 'eg-other-question-options']),
            ])->extraAttributes(['class' => 'eg-other-question-main'])->columnSpanFull(),
        ];
    }

    public static function settings(): array
    {
        return [
            Select::make('question.type')->label(__('feedback.question_type'))->options(['choice' => __('feedback.choice_question'), 'text' => __('feedback.text_question')])->default('choice')->required()->live()
                ->afterStateUpdated(fn (Set $set) => $set('question.options', [])),
            Toggle::make('question.required')->label(__('feedback.answer_required'))->default(false),
            TextInput::make('question.max_choices')->label(__('feedback.max_selections'))->numeric()->minValue(1)->maxValue(50)->default(1)->required()->visible(fn (Get $get) => $get('question.type') === 'choice')->dehydratedWhenHidden(),
            TextInput::make('question.max_length')->label(__('feedback.text_limit'))->numeric()->minValue(1)->maxValue(10000)->default(2000)->required()->visible(fn (Get $get) => $get('question.type') === 'text')->dehydratedWhenHidden(),
            Hidden::make('question.rating_reasons_enabled')->default(false)->dehydrateStateUsing(static fn (): bool => false),
            Hidden::make('question.rating_reasons_required')->default(false)->dehydrateStateUsing(static fn (): bool => false),
        ];
    }

    private static function countedInput(string $name, string $label, int $limit): TextInput
    {
        return TextInput::make($name)->label($label)->required()->maxLength($limit)
            ->extraFieldWrapperAttributes(fn (TextInput $component): array => [
                'class' => 'eg-other-question-counted',
                'x-data' => '{ characterCount: '.(is_string($component->getState()) ? mb_strlen($component->getState()) : 0).' }',
                'x-on:input' => 'characterCount = Array.from($event.target.value || "").length',
            ])
            ->suffix(new HtmlString('<span class="eg-question-char-count" x-text="characterCount + \'/'.$limit.'\'">0/'.$limit.'</span>'));
    }

    private static function languagePair(string $path, string $label, int $limit, string $placeholder): Group
    {
        return Group::make([
            self::countedInput($path.'.lo', $label.' · '.__('feedback.other_question_lao'), $limit)->placeholder($placeholder),
            self::countedInput($path.'.en', $label.' · '.__('feedback.other_question_english'), $limit)->placeholder(__('feedback.other_question_english_placeholder')),
        ])->extraAttributes([
            'class' => 'eg-other-question-language-pair',
            'role' => 'group',
            'aria-label' => $label.' · '.__('feedback.other_question_lao').' / '.__('feedback.other_question_english'),
        ]);
    }

    public static function versionStatusLabel(string $status): string
    {
        return trans()->has('feedback.other_question_version_states.'.$status)
            ? __('feedback.other_question_version_states.'.$status)
            : $status;
    }

    public static function data(Question $question): array
    {
        $version = $question->version->load('survey', 'questions.options');
        $data = collect(SurveyFields::data($version)['questions'])->firstWhere('code', $question->code);

        return ['revision' => $version->revision, 'question' => $data];
    }
}
