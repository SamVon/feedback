<?php

namespace App\Filament\Resources;

use App\Application\Access;
use App\Application\ReportFilters;
use App\Filament\Support\FilterFields;
use App\Filament\Support\ReadOnlyResource;
use App\Models\FeedbackResponse;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class FeedbackResource extends ReadOnlyResource
{
    protected static ?string $model = FeedbackResponse::class;

    protected static string $permission = 'feedback.read';

    protected static ?string $navigationLabel = 'feedback';

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('actor_user_id')->label(__('feedback.customer'))->searchable(),
            TextColumn::make('version.survey.name')->label(__('feedback.survey')), TextColumn::make('version.version')->label(__('feedback.version')),
            TextColumn::make('answers.rating_value')->label(__('feedback.rating'))->listWithLineBreaks(),
            TextColumn::make('source')->label(__('feedback.source'))->badge(), TextColumn::make('context.transaction_type')->label(__('feedback.transaction_type'))->placeholder('—'),
            TextColumn::make('received_at')->label(__('feedback.received_at'))->dateTime('d M Y H:i')->timezone('Asia/Vientiane')->sortable(),
        ])->defaultSort('received_at', 'desc')->filters([
            Filter::make('report')->schema(FilterFields::make())->query(fn ($query, array $data) => $query->whereIn('feedback_responses.id', ReportFilters::query($data)->select('id'))),
        ])->recordActions([
            Action::make('details')->label(__('feedback.details'))->modalContent(function (FeedbackResponse $record) {
                Access::check(auth()->user(), 'feedback.read');

                return view('filament.partials.response', ['response' => $record->load('answers.question', 'answers.options', 'context')]);
            })->modalSubmitAction(false),
        ])->emptyStateHeading(__('feedback.empty'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFeedback::route('/')];
    }
}
