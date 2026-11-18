<?php

namespace App\Filament\Support;

use App\Models\Question;
use App\Models\Survey;
use App\Models\SurveyVersion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

final class FilterFields
{
    public static function make(): array
    {
        return [
            DatePicker::make('from')->label(__('feedback.from'))->default(now('Asia/Vientiane')->subDays(29)->toDateString()),
            DatePicker::make('until')->label(__('feedback.until'))->default(now('Asia/Vientiane')->toDateString())->afterOrEqual('from'),
            Select::make('survey_id')->label(__('feedback.survey'))->options(fn () => Survey::pluck('name', 'id'))->live()->afterStateUpdated(function ($set) {
                $set('survey_version_id', null);
                $set('question_id', null);
            }),
            Select::make('survey_version_id')->label(__('feedback.version'))->options(fn (Get $get) => SurveyVersion::with('survey')->when($get('survey_id'), fn ($q, $id) => $q->where('survey_id', $id))->where('status', 'published')->get()->mapWithKeys(fn ($v) => [$v->id => $v->survey->name.' · v'.$v->version]))->live()->afterStateUpdated(fn ($set) => $set('question_id', null)),
            Select::make('question_id')->label(__('feedback.question'))->options(fn (Get $get) => Question::where('survey_version_id', $get('survey_version_id'))->get()->mapWithKeys(fn ($q) => [$q->id => $q->label[app()->getLocale()] ?? $q->label['en']]))->helperText(__('feedback.question_hint')),
            Select::make('source')->label(__('feedback.source'))->options(['auto_prompt' => 'Auto prompt', 'always_on' => 'Always on']),
            TextInput::make('actor_user_id')->label(__('feedback.customer'))->maxLength(100),
            Select::make('rating')->label(__('feedback.rating'))->options([5 => '5 ★', 4 => '4 ★', 3 => '3 ★ · '.__('feedback.neutral'), 2 => '2 ★', 1 => '1 ★']),
            TextInput::make('transaction_type')->label(__('feedback.transaction_type'))->maxLength(50),
            TextInput::make('app_version')->label(__('feedback.app_version'))->maxLength(40),
            Select::make('os')->label('OS')->options(['android' => 'Android', 'ios' => 'iOS', 'other' => 'Other']),
        ];
    }
}
