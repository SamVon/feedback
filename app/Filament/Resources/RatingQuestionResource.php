<?php

namespace App\Filament\Resources;

use App\Filament\Support\SurveyFields;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RatingQuestionResource extends SurveyResource
{
    protected static ?string $navigationLabel = 'rating_questions';

    protected static ?string $slug = 'rating-questions';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-s-star';

    protected static function previewSource(): string
    {
        return 'auto_prompt';
    }

    protected static function fields(): array
    {
        return SurveyFields::make(ratingMode: true);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('questions', fn (Builder $query) => $query->where('type', 'rating'))->with('questions.options', 'survey');
    }

    public static function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('survey.name')->label(__('feedback.survey'))->searchable(),
            TextColumn::make('rating_questions')->label(__('feedback.question'))->state(fn ($record) => $record->questions->where('type', 'rating')->map(fn ($question) => $question->label[app()->getLocale()] ?? $question->label['en'])->all())->listWithLineBreaks()->wrap(),
        ];
        foreach (range(5, 1) as $rating) {
            $columns[] = TextColumn::make('reasons_'.$rating)->label(__('feedback.stars_count', ['count' => $rating]))
                ->state(fn ($record) => $record->questions->where('type', 'rating')->flatMap(fn ($question) => $question->options->where('rating_value', $rating)->map(fn ($option) => $option->label[app()->getLocale()] ?? $option->label['en']))->all())
                ->listWithLineBreaks()->limitList(3)->expandableLimitedList()->wrap()->placeholder('—');
        }
        $columns[] = TextColumn::make('version')->label(__('feedback.version'))->sortable();
        $columns[] = TextColumn::make('status')->label(__('feedback.status'))->badge();
        $columns[] = TextColumn::make('created_at')->label(__('feedback.revision_created'))->dateTime()->sortable();

        $table = parent::table($table);
        foreach ($table->getRecordActions() as $action) {
            if (in_array($action->getName(), ['editDraft', 'newVersion'], true)) {
                $action->url(fn ($record) => static::getUrl('edit', ['record' => $record]))->schema([])->action(null);
                if ($action->getName() === 'newVersion') {
                    $action->visible(fn ($record) => $record->status !== 'draft' && auth()->user()->can('survey.edit'));
                }
            }
        }

        return $table->headerActions([
            Action::make('createRating')->label(__('feedback.create_rating_question'))->icon('heroicon-o-plus')->visible(fn () => auth()->user()->can('survey.edit'))->url(static::getUrl('create')),
        ])->columns($columns)->filters([
            Filter::make('history_dates')->schema([
                DatePicker::make('from')->label(__('feedback.from')),
                DatePicker::make('until')->label(__('feedback.until'))->afterOrEqual('from'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->where('created_at', '>=', CarbonImmutable::parse($date, 'Asia/Vientiane')->startOfDay()->utc()))
                ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->where('created_at', '<', CarbonImmutable::parse($date, 'Asia/Vientiane')->startOfDay()->addDay()->utc()))),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRatingQuestions::route('/'), 'create' => Pages\EditRatingQuestion::route('/create'), 'edit' => Pages\EditRatingQuestion::route('/{record}/edit')];
    }
}
