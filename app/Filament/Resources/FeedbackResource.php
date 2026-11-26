<?php

namespace App\Filament\Resources;

use App\Application\ReportFilters;
use App\Filament\Support\ReadOnlyResource;
use App\Models\FeedbackResponse;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FeedbackResource extends ReadOnlyResource
{
    protected static ?string $model = FeedbackResponse::class;

    protected static string $permission = 'feedback.read';

    protected static ?string $navigationLabel = 'feedback_history';

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('answers')->with('answers.question', 'answers.options', 'context', 'version.survey');
    }

    public static function table(Table $table): Table
    {
        $detailUrl = fn (FeedbackResponse $record, Pages\ListFeedback $livewire): string => static::getUrl('view', ['record' => $record->id, 'filters' => $livewire->dataFilters(), 'search' => $livewire->tableSearch]);

        return $table->columns([
            TextColumn::make('number')->label('#')->rowIndex(),
            TextColumn::make('received_at')->label(__('feedback.history_response_time'))->dateTime('d/m/Y H:i', timezone: 'Asia/Vientiane')->sortable(),
            TextColumn::make('customer_reference')->label(__('feedback.customer'))->state(fn (FeedbackResponse $record): ?string => $record->customer_phone)->placeholder('—')
                ->visible(fn (): bool => auth()->user()->can('customer_identity.read')),
            TextColumn::make('actor_user_id')->label(__('feedback.customer_user_id'))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('customer_name')->label(__('feedback.history_customer_name'))->placeholder('—')->limit(24)
                ->visible(fn (): bool => auth()->user()->can('customer_identity.read')),
            TextColumn::make('customer_phone')->label(__('feedback.customer_phone'))->placeholder('—')
                ->visible(fn (): bool => auth()->user()->can('customer_identity.read')),
            ViewColumn::make('question')->label(__('feedback.question'))->view('filament.partials.feedback-history-question'),
            ViewColumn::make('rating')->label(__('feedback.history_satisfaction'))->view('filament.partials.customer-rating'),
            ViewColumn::make('selected_answers')->label(__('feedback.answers'))->view('filament.partials.feedback-history-answers'),
            ViewColumn::make('feedback_text')->label(__('feedback.customer_additional_feedback'))->view('filament.partials.feedback-history-comments'),
            TextColumn::make('version.survey.name')->label(__('feedback.survey'))->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('version.version')->label(__('feedback.version'))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('source')->label(__('feedback.source'))->badge()
                ->formatStateUsing(fn (string $state): string => __('feedback.history_source_'.$state))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('context.transaction_type')->label(__('feedback.transaction_type'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ])->defaultSort('received_at', 'desc')->paginated([10, 25, 50])->searchable()
            ->searchUsing(fn (Builder $query, string $search): Builder => ReportFilters::searchPhone($query, $search, 'customer_phone', 'feedback_responses.id'))
            ->searchPlaceholder(__('feedback.customer_search_phone'))->recordUrl($detailUrl)->recordActions([
                Action::make('details')->label(__('feedback.details'))->icon('heroicon-m-ellipsis-vertical')->color('gray')
                    ->authorize(fn (): bool => auth()->user()?->can('feedback.read') ?? false)
                    ->url($detailUrl),
            ])->emptyStateHeading(__('feedback.empty'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFeedback::route('/'), 'view' => Pages\ViewFeedback::route('/{record}')];
    }
}
