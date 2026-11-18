<?php

namespace App\Filament\Support;

use App\Models\Question;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

final class OtherQuestionFields
{
    public static function make(): array
    {
        return [
            Hidden::make('question.code')->default(fn () => 'q_'.Str::random(12)),
            Select::make('question.type')->label(__('feedback.question_type'))->options(['choice' => __('feedback.choice_question'), 'text' => __('feedback.text_question')])->default('choice')->required()->live()->afterStateUpdated(fn (Set $set) => $set('question.options', [])),
            TextInput::make('question.label.en')->label(__('feedback.question').' · English')->required()->maxLength(500),
            TextInput::make('question.label.lo')->label(__('feedback.question').' · ລາວ')->required()->maxLength(500),
            Toggle::make('question.enabled')->label(__('feedback.question_enabled'))->default(true),
            Toggle::make('question.required')->label(__('feedback.answer_required'))->default(false),
            TextInput::make('question.max_choices')->label(__('feedback.max_selections'))->numeric()->minValue(1)->maxValue(50)->default(1)->required()->visible(fn (Get $get) => $get('question.type') === 'choice')->dehydratedWhenHidden(),
            TextInput::make('question.max_length')->label(__('feedback.text_limit'))->numeric()->minValue(1)->maxValue(10000)->default(2000)->required()->visible(fn (Get $get) => $get('question.type') === 'text')->dehydratedWhenHidden(),
            Hidden::make('question.rating_reasons_enabled')->default(false),
            Hidden::make('question.rating_reasons_required')->default(false),
            Repeater::make('question.options')->label(__('feedback.options'))->defaultItems(1)->minItems(fn (Get $get) => $get('question.type') === 'choice' ? 1 : 0)->maxItems(50)->reorderableWithButtons()
                ->visible(fn (Get $get) => $get('question.type') === 'choice')->dehydratedWhenHidden()->dehydrateStateUsing(fn ($state, Get $get) => $get('question.type') === 'choice' ? array_values($state ?? []) : [])
                ->schema([Hidden::make('code')->default(fn () => Str::random(12)), TextInput::make('label.en')->label('English')->required()->maxLength(200), TextInput::make('label.lo')->label('ລາວ')->required()->maxLength(200)])->columns(2),
        ];
    }

    public static function data(Question $question): array
    {
        $version = $question->version->load('survey', 'questions.options');
        $data = collect(SurveyFields::data($version)['questions'])->firstWhere('code', $question->code);

        return ['revision' => $version->revision, 'question' => $data];
    }
}
