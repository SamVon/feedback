<?php

namespace App\Filament\Resources;

use App\Application\OtherQuestions;
use App\Filament\Support\OtherQuestionFields;
use App\Filament\Support\ReadOnlyResource;
use App\Models\Question;
use App\Models\SurveyVersion;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            TextColumn::make('version.status')->label(__('feedback.version_status'))->badge(),
        ])->defaultSort(fn (Builder $query) => $query->orderByDesc('survey_version_id')->orderBy('position'))->filters([
            Filter::make('latest')->label(__('feedback.latest_versions'))->toggle()->default()->query(fn (Builder $query) => $query->whereHas('version', fn (Builder $version) => $version->whereRaw('version = (select max(latest.version) from survey_versions as latest where latest.survey_id = survey_versions.survey_id)'))),
            SelectFilter::make('enabled')->label(__('feedback.question_status'))->options([1 => __('feedback.popup_yes'), 0 => __('feedback.popup_no')]),
            Filter::make('history_dates')->schema([DatePicker::make('from')->label(__('feedback.from')), DatePicker::make('until')->label(__('feedback.until'))->afterOrEqual('from')])->columns(2)->query(fn (Builder $query, array $data) => $query->whereHas('version', fn (Builder $version) => $version
                ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->where('created_at', '>=', CarbonImmutable::parse($date, 'Asia/Vientiane')->startOfDay()->utc()))
                ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->where('created_at', '<', CarbonImmutable::parse($date, 'Asia/Vientiane')->startOfDay()->addDay()->utc())))),
        ], layout: FiltersLayout::AboveContent)->headerActions([
            Action::make('createQuestion')->label(__('feedback.create_question'))->icon('heroicon-o-plus')->visible($editable)->schema([
                Select::make('survey_version_id')->label(__('feedback.base_survey_version'))->options(fn () => SurveyVersion::with('survey')->orderByDesc('created_at')->get()->mapWithKeys(fn ($version) => [$version->id => $version->survey->name.' · v'.$version->version.' · '.$version->status]))->required()->live()->afterStateUpdated(fn ($state, Set $set) => $set('revision', SurveyVersion::find($state)?->revision)),
                ...$revision, ...OtherQuestionFields::make(),
            ])->modalDescription(__('feedback.question_publish_hint'))->action(function (array $data) {
                static::saved(app(OtherQuestions::class)->save(auth()->user(), $data['survey_version_id'], (int) $data['revision'], null, $data['question']));
            }),
        ])->recordActions([
            Action::make('editQuestion')->label(__('feedback.edit_question'))->icon('heroicon-o-pencil-square')->visible($editable)->fillForm(fn ($record) => OtherQuestionFields::data($record))->schema([...$revision, ...OtherQuestionFields::make()])->modalDescription(__('feedback.question_publish_hint'))->action(function ($record, array $data) {
                static::saved(app(OtherQuestions::class)->save(auth()->user(), $record->survey_version_id, (int) $data['revision'], $record->id, $data['question']));
            }),
            Action::make('toggleEnabled')->label(fn ($record) => $record->enabled ? __('feedback.close_question') : __('feedback.open_question'))->icon('heroicon-o-power')->color(fn ($record) => $record->enabled ? 'danger' : 'success')->visible($editable)->fillForm(fn ($record) => ['revision' => $record->version->revision])->schema($revision)->requiresConfirmation()->modalDescription(__('feedback.question_publish_hint'))->action(function ($record, array $data) {
                $question = OtherQuestionFields::data($record)['question'];
                $question['enabled'] = ! $record->enabled;
                static::saved(app(OtherQuestions::class)->save(auth()->user(), $record->survey_version_id, (int) $data['revision'], $record->id, $question));
            }),
            Action::make('removeQuestion')->label(__('feedback.remove_question'))->icon('heroicon-o-trash')->color('danger')->visible($editable)->fillForm(fn ($record) => ['revision' => $record->version->revision])->schema($revision)->requiresConfirmation()->modalDescription(__('feedback.question_remove_hint'))->action(function ($record, array $data) {
                static::saved(app(OtherQuestions::class)->save(auth()->user(), $record->survey_version_id, (int) $data['revision'], $record->id, remove: true));
            }),
        ])->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOtherQuestions::route('/')];
    }
}
