<?php

namespace App\Filament\Resources;

use App\Application\Access;
use App\Application\Changes;
use App\Filament\Support\ReadOnlyResource;
use App\Filament\Support\SurveyFields;
use App\Models\SurveyVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SurveyResource extends ReadOnlyResource
{
    protected static ?string $model = SurveyVersion::class;

    protected static string $permission = 'survey.read';

    protected static ?string $navigationLabel = 'surveys';

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static function previewSource(): string
    {
        return 'always_on';
    }

    protected static function fields(): array
    {
        return SurveyFields::make();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('survey.name')->label(__('feedback.survey'))->searchable(), TextColumn::make('version')->label(__('feedback.version'))->sortable(), TextColumn::make('revision'), TextColumn::make('status')->label(__('feedback.status'))->badge(), TextColumn::make('published_at')->dateTime(),
        ])->defaultSort('created_at', 'desc')->headerActions([
            Action::make('draft')->label(__('feedback.new_survey'))->visible(fn () => auth()->user()->can('survey.edit'))->schema(static::fields())->action(fn (array $data) => app(Changes::class)->surveyDraft(auth()->user(), $data)),
        ])->recordActions([
            Action::make('preview')->label(__('feedback.preview'))->modalContent(function ($record) {
                Access::check(auth()->user(), 'survey.read');

                return view('filament.partials.survey-preview', ['version' => $record->load('questions.options', 'survey'), 'source' => static::previewSource()]);
            })->modalSubmitAction(false),
            Action::make('editDraft')->label(__('feedback.edit_draft'))->visible(fn ($record) => $record->status === 'draft' && auth()->user()->can('survey.edit'))->fillForm(fn ($record) => SurveyFields::data($record))->schema(static::fields())->action(fn ($record, array $data) => app(Changes::class)->surveyDraft(auth()->user(), $data, $record->id, (int) $data['revision'])),
            Action::make('newVersion')->label(__('feedback.new_version'))->visible(fn () => auth()->user()->can('survey.edit'))->fillForm(fn ($record) => SurveyFields::data($record))->schema(static::fields())->action(fn (array $data) => app(Changes::class)->surveyDraft(auth()->user(), $data)),
            Action::make('publish')->label(__('feedback.publish'))->visible(fn ($record) => in_array($record->status, ['draft', 'submitted', 'approved'], true) && auth()->user()->can('changes.publish'))->fillForm(fn ($record) => ['revision' => $record->revision])->schema([Hidden::make('revision')])->requiresConfirmation()->action(fn ($record, array $data) => app(Changes::class)->transition(auth()->user(), 'survey', $record->id, (int) $data['revision'], 'publish')),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSurveys::route('/')];
    }
}
