<?php

namespace App\Filament\Support;

use App\Application\ChannelPolicy;
use App\Models\SurveyVersion;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

final class SurveyFields
{
    public static function make(bool $ratingMode = false, bool $otherMode = false): array
    {
        $reasonTabs = [];
        foreach (range(5, 1) as $rating) {
            $reasonTabs[] = Tab::make(__('feedback.stars_count', ['count' => $rating]))->schema([
                Repeater::make('rating_reasons.'.$rating)->label(__('feedback.reasons_for_rating', ['count' => $rating]))
                    ->minItems(fn (Get $get): int => $get('../../rating_reasons_required') ? 1 : 0)->maxItems(50)->defaultItems(1)->reorderableWithButtons()
                    ->addActionLabel(__('feedback.add_reason'))->schema([
                        Hidden::make('code')->default(fn (): string => Str::random(12)),
                        TextInput::make('label.en')->label('English')->required()->maxLength(100),
                        TextInput::make('label.lo')->label('ລາວ')->required()->maxLength(100),
                    ])->columns(2),
            ]);
        }

        return [
            Hidden::make('revision'), TextInput::make('code')->label(__('feedback.code'))->required()->maxLength(60), TextInput::make('name')->label(__('feedback.name'))->required()->maxLength(200),
            CheckboxList::make('channels')->label(__('feedback.survey_channels'))->options(['auto_prompt' => 'Auto prompt', 'always_on' => 'Always on'])->default(['auto_prompt', 'always_on'])->required()->minItems(1)->columnSpanFull(),
            Section::make(__('feedback.localized_content'))->collapsed()->schema(collect(['title', 'intro', 'submit', 'cancel', 'thank_you'])->map(fn (string $key) => Section::make(__('feedback.content_'.$key))->schema([
                TextInput::make('content.'.$key.'.en')->label('English')->default(fn () => ChannelPolicy::content(new SurveyVersion)[$key]['en'])->required()->maxLength(500),
                TextInput::make('content.'.$key.'.lo')->label('ລາວ')->default(fn () => ChannelPolicy::content(new SurveyVersion)[$key]['lo'])->required()->maxLength(500),
            ])->columns(2))->all())->columnSpanFull(),
            Repeater::make('questions')->label(__('feedback.questions'))->minItems(1)->maxItems(50)->schema([
                TextInput::make('code')->required(), Select::make('type')->options(['rating' => 'Rating 1–5', 'choice' => 'Multiple choice', 'text' => 'Text'])->required()->default($otherMode ? 'choice' : 'rating')->live()->afterStateUpdated(function ($state, Set $set): void {
                    if ($state !== 'rating') {
                        $set('rating_reasons_enabled', false);
                        $set('rating_reasons_required', false);
                        $set('rating_reasons', []);
                    }
                    if ($state !== 'choice') {
                        $set('options', []);
                    }
                }),
                TextInput::make('label.en')->label('English')->required(), TextInput::make('label.lo')->label('ລາວ')->required(),
                Toggle::make('enabled')->label(__('feedback.question_enabled'))->default(true)->helperText(__('feedback.question_version_hint')),
                Toggle::make('required')->label(__('feedback.answer_required'))->default(true),
                TextInput::make('max_length')->label(fn (Get $get): string => $get('type') === 'rating' ? __('feedback.rating_comment_limit') : __('feedback.text_limit'))->numeric()->minValue(1)->maxValue(10000)->default(2000)->required()->visible(fn (Get $get): bool => in_array($get('type'), ['rating', 'text'], true))->dehydratedWhenHidden(),
                TextInput::make('max_choices')->label(__('feedback.max_selections'))->numeric()->default($ratingMode ? 3 : 1)->required()
                    ->maxValue(50)->minValue(1)->helperText(__('feedback.selection_policy_hint'))
                    ->visible(fn (Get $get): bool => $get('type') === 'choice' || ($get('type') === 'rating' && $get('rating_reasons_enabled')))->dehydratedWhenHidden(),
                Toggle::make('rating_reasons_enabled')->label(__('feedback.enable_rating_reasons'))->default($ratingMode)->live()->afterStateUpdated(function ($state, Set $set): void {
                    if (! $state) {
                        $set('rating_reasons', []);
                        $set('rating_reasons_required', false);
                    }
                })
                    ->visible(fn (Get $get): bool => $get('type') === 'rating')->dehydrateStateUsing(fn ($state, Get $get): bool => $get('type') === 'rating' && (bool) $state)->dehydratedWhenHidden(),
                Toggle::make('rating_reasons_required')->label(__('feedback.reasons_required'))->default(false)
                    ->visible(fn (Get $get): bool => $get('type') === 'rating' && $get('rating_reasons_enabled'))
                    ->dehydrateStateUsing(fn ($state, Get $get): bool => $get('type') === 'rating' && $get('rating_reasons_enabled') && (bool) $state)->dehydratedWhenHidden(),
                Tabs::make('rating_reason_tabs')->tabs($reasonTabs)->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('type') === 'rating' && $get('rating_reasons_enabled')),
                Repeater::make('options')->label(__('feedback.options'))->defaultItems(0)
                    ->visible(fn (Get $get): bool => $get('type') === 'choice')
                    ->dehydrateStateUsing(fn ($state, Get $get): array => $get('type') === 'choice' ? array_values($state ?? []) : [])->dehydratedWhenHidden()
                    ->reorderableWithButtons()->schema([TextInput::make('code')->required(), TextInput::make('label.en')->label('English')->required(), TextInput::make('label.lo')->label('ລາວ')->required()])->columns(3)->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
        ];
    }

    public static function data(SurveyVersion $version): array
    {
        return ['code' => $version->survey->code, 'name' => $version->survey->name, 'revision' => $version->revision, 'channels' => $version->channels ?? ['auto_prompt', 'always_on'], 'content' => ChannelPolicy::content($version), 'questions' => $version->questions->map(function ($question): array {
            $data = $question->only(['code', 'type', 'label', 'enabled', 'required', 'max_length', 'max_choices', 'rating_reasons_enabled', 'rating_reasons_required']);
            $data['options'] = $question->options->whereNull('rating_value')->map(fn ($option): array => $option->only(['code', 'label']))->values()->all();
            $data['rating_reasons'] = [];
            if ($question->rating_reasons_enabled) {
                foreach (range(5, 1) as $rating) {
                    $data['rating_reasons'][$rating] = $question->options->where('rating_value', $rating)->map(fn ($option): array => [
                        'code' => Str::after($option->code, 'r'.$rating.'_'), 'label' => $option->label,
                    ])->values()->all();
                }
            }

            return $data;
        })->all()];
    }
}
