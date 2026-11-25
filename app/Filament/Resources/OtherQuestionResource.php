<?php

namespace App\Filament\Resources;

use App\Application\Changes;
use App\Application\OtherQuestions;
use App\Application\ReportFilters;
use App\Filament\Support\FilterFields;
use App\Filament\Support\OtherQuestionFields;
use App\Filament\Support\ReadOnlyResource;
use App\Models\Question;
use App\Models\SurveyVersion;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\FiltersResetActionPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OtherQuestionResource extends ReadOnlyResource
{
    protected static ?string $model = Question::class;

    protected static string $permission = 'survey.read';

    protected static ?string $navigationLabel = 'other_questions';

    protected static ?string $slug = 'other-questions';

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('type', ['choice', 'text'])->with('options', 'version.survey', 'version.lastEdit.staff');
    }

    private static function saved(SurveyVersion $version): void
    {
        Notification::make()->success()->title(__('feedback.question_draft_saved', ['version' => $version->version]))->body(__('feedback.question_publish_hint'))->actions([Action::make('survey')->label(__('feedback.surveys'))->url(SurveyResource::getUrl())])->send();
    }

    public static function table(Table $table): Table
    {
        $editable = fn () => auth()->user()->can('survey.edit');
        $revision = [Hidden::make('revision')];

        return $table->heading(__('feedback.other_questions'))->description(__('feedback.other_question_scope'))->columns([
            TextColumn::make('number')->label('#')->rowIndex(),
            TextColumn::make('label')->label(__('feedback.question'))->state(fn ($record) => $record->label[app()->getLocale()] ?? $record->label['en'])->description(fn ($record) => $record->version->survey->name.' · v'.$record->version->version)->searchable(['label'])->wrap(),
            TextColumn::make('answer_labels')->label(__('feedback.answers'))->state(fn ($record) => $record->options->map(fn ($option) => $option->label[app()->getLocale()] ?? $option->label['en'])->all())->listWithLineBreaks()->bulleted()->wrap()->placeholder('—'),
            TextColumn::make('enabled')->label(__('feedback.question_status'))->formatStateUsing(fn ($state) => $state ? __('feedback.popup_yes') : __('feedback.popup_no'))->badge()->color(fn ($state) => $state ? 'success' : 'danger'),
            TextColumn::make('opened_at')->label(__('feedback.question_opened_at'))->state(fn ($record) => $record->enabled ? $record->version->published_at : null)->dateTime('d/m/Y H:i', 'Asia/Vientiane')->placeholder('—'),
            TextColumn::make('closed_at')->label(__('feedback.question_closed_at'))->state(fn ($record) => ! $record->enabled ? $record->version->published_at : null)->dateTime('d/m/Y H:i', 'Asia/Vientiane')->placeholder('—'),
            TextColumn::make('version.lastEdit.staff.name')->label(__('feedback.operator'))->placeholder('—'),
            TextColumn::make('version.status')->label(__('feedback.version_status'))->formatStateUsing(fn (string $state): string => OtherQuestionFields::versionStatusLabel($state))->badge(),
        ])->defaultSort(fn (Builder $query) => $query->orderByDesc('survey_version_id')->orderBy('position'))->filters([
            SelectFilter::make('enabled')->label(__('feedback.question_status'))->placeholder(__('feedback.question_all_statuses'))
                ->options([1 => __('feedback.popup_yes'), 0 => __('feedback.popup_no')])->columnSpan(1),
            Filter::make('history_dates')->schema([
                FilterFields::dateTime('from', __('feedback.question_created_from')),
                FilterFields::dateTime('until', __('feedback.question_created_until')),
            ])->columns(['default' => 1, 'sm' => 2])->columnSpan(['default' => 1, 'md' => 2])->indicateUsing(function (array $data): array {
                $indicators = [];
                foreach (['from' => 'question_created_from', 'until' => 'question_created_until'] as $field => $label) {
                    if (filled($data[$field] ?? null)) {
                        $indicators[] = Indicator::make(__('feedback.'.$label).': '.CarbonImmutable::parse(ReportFilters::dateInput($data[$field], $field === 'until'), 'Asia/Vientiane')->format('d/m/Y H:i'))->removeField($field);
                    }
                }

                return $indicators;
            })->query(fn (Builder $query, array $data) => $query->whereHas('version', fn (Builder $version) => ReportFilters::applyDateRange($version, $data, 'created_at'))),
            Filter::make('latest')->label(__('feedback.latest_versions'))->toggle()->default()->columnSpanFull()
                ->modifyFormFieldUsing(fn ($field) => $field->helperText(__('feedback.question_latest_hint'))->extraFieldWrapperAttributes(['class' => 'eg-question-latest-filter']))
                ->query(fn (Builder $query) => $query->whereHas('version', fn (Builder $version) => $version->whereRaw('version = (select max(latest.version) from survey_versions as latest where latest.survey_id = survey_versions.survey_id)'))),
        ], layout: FiltersLayout::AboveContent)->filtersFormColumns(['default' => 1, 'md' => 3])
            ->filtersResetActionPosition(FiltersResetActionPosition::Footer)
            ->filtersApplyAction(fn (Action $action): Action => $action->label(__('feedback.question_apply_filters'))->icon('heroicon-o-magnifying-glass'))
            ->filtersResetAction(fn (Action $action): Action => $action->label(__('feedback.question_reset_filters'))->icon('heroicon-o-arrow-path')->color('gray')->outlined()->button())
            ->searchPlaceholder(__('feedback.question_search_placeholder'))
            ->extraAttributes(['class' => 'eg-other-questions-table'])->headerActions([
                Action::make('createQuestion')->label(__('feedback.create_question'))->icon('heroicon-o-plus')->visible($editable)->url(static::getUrl('create')),
            ])->recordActions([
                Action::make('editQuestion')->label(__('feedback.edit_question'))->icon('heroicon-o-pencil-square')->visible($editable)->url(fn ($record) => static::getUrl('edit', ['record' => $record])),
                Action::make('toggleEnabled')->label(fn ($record) => $record->enabled ? __('feedback.close_question') : __('feedback.open_question'))->icon('heroicon-o-power')->color(fn ($record) => $record->enabled ? 'danger' : 'success')->visible(fn ($record) => auth()->user()->can('survey.edit') && ($record->enabled || auth()->user()->can('changes.publish')))->fillForm(fn ($record) => ['revision' => $record->version->revision])->schema($revision)->requiresConfirmation()
                    ->modalSubmitActionLabel(fn ($record) => $record->enabled ? __('feedback.close_question') : __('feedback.open_question'))
                    ->modalCancelActionLabel(__('feedback.other_question_cancel'))
                    ->modalDescription(fn ($record) => $record->enabled ? __('feedback.question_publish_hint') : __('feedback.question_enable_publish_hint'))->action(function ($record, array $data) {
                        $question = OtherQuestionFields::data($record)['question'];
                        $question['enabled'] = ! $record->enabled;
                        if (! $question['enabled']) {
                            static::saved(app(OtherQuestions::class)->save(auth()->user(), $record->survey_version_id, (int) $data['revision'], $record->id, $question));

                            return;
                        }
                        DB::transaction(function () use ($record, $data, $question) {
                            $version = app(OtherQuestions::class)->save(auth()->user(), $record->survey_version_id, (int) $data['revision'], $record->id, $question);
                            app(Changes::class)->transition(auth()->user(), 'survey', $version->id, $version->revision, 'publish');
                        }, 5);
                        Notification::make()->success()->title(__('feedback.question_enabled_published'))->send();
                    }),
                Action::make('removeQuestion')->label(__('feedback.remove_question'))->icon('heroicon-o-trash')->color('danger')->visible($editable)->fillForm(fn ($record) => ['revision' => $record->version->revision])->schema($revision)->requiresConfirmation()
                    ->modalSubmitActionLabel(__('feedback.remove_question'))
                    ->modalCancelActionLabel(__('feedback.other_question_cancel'))
                    ->modalDescription(__('feedback.question_remove_hint'))->action(function ($record, array $data) {
                        static::saved(app(OtherQuestions::class)->save(auth()->user(), $record->survey_version_id, (int) $data['revision'], $record->id, remove: true));
                    }),
            ])->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOtherQuestions::route('/'), 'create' => Pages\EditOtherQuestion::route('/create'), 'edit' => Pages\EditOtherQuestion::route('/{record}/edit')];
    }
}
