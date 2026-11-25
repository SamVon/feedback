<?php

namespace App\Filament\Resources;

use App\Application\Access;
use App\Application\Changes;
use App\Application\ChannelPolicy;
use App\Application\PopupPolicy;
use App\Application\ReportFilters;
use App\Filament\Support\FilterFields;
use App\Filament\Resources\Pages\ListRules;
use App\Filament\Support\ReadOnlyResource;
use App\Filament\Support\RuleEditorAction;
use App\Models\DailyFeedbackLimit;
use App\Models\RuleVersion;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RuleResource extends ReadOnlyResource
{
    protected static ?string $model = RuleVersion::class;

    protected static string $permission = 'rules.read';

    protected static ?string $navigationLabel = 'rules';

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    public static function scheduleLabel(array $rules): string
    {
        $schedule = ChannelPolicy::schedule($rules);
        $format = fn (string $date): string => CarbonImmutable::parse($date)->setTimezone('Asia/Vientiane')->format('d/m/Y H:i');

        return match (true) {
            $schedule['starts_at'] && $schedule['ends_at'] => $format($schedule['starts_at']).' – '.$format($schedule['ends_at']),
            (bool) $schedule['starts_at'] => __('feedback.from').' '.$format($schedule['starts_at']),
            (bool) $schedule['ends_at'] => __('feedback.until').' '.$format($schedule['ends_at']),
            default => __('feedback.rules_no_schedule'),
        };
    }

    public static function durationLabel(array $rules): string
    {
        $schedule = ChannelPolicy::schedule($rules);
        if (! $schedule['starts_at'] || ! $schedule['ends_at']) {
            return '—';
        }

        $hours = CarbonImmutable::parse($schedule['starts_at'])->diffInHours(CarbonImmutable::parse($schedule['ends_at']));
        if ($hours <= 0) {
            return '—';
        }

        return $hours < 24
            ? __('feedback.rules_hours', ['count' => round($hours, 2)])
            : __('feedback.rules_days', ['count' => round($hours / 24, 2)]);
    }

    public static function transactionLabels(array $rules): string
    {
        return collect(PopupPolicy::normalizeRules($rules)['transaction_rules'])->where('enabled', true)
            ->map(fn (array $rule): string => __('feedback.rules_transaction_names')[$rule['transaction_type']] ?? $rule['transaction_type'])
            ->implode(', ');
    }

    public static function activeState(array $rules): string
    {
        if (! ($rules['channels']['auto_prompt'] ?? false)) {
            return 'off';
        }
        if (ChannelPolicy::popupOpen($rules)) {
            return 'on';
        }

        $start = ChannelPolicy::schedule($rules)['starts_at'];

        return $start && now()->lt(CarbonImmutable::parse($start)) ? 'scheduled' : 'ended';
    }

    public static function table(Table $table): Table
    {
        if ($table->getLivewire() instanceof ListRules && $table->getLivewire()->showsDailyLimit()) {
            return $table
                ->query(DailyFeedbackLimit::query()->with('editor'))
                ->heading(__('feedback.rules_history'))
                ->columns([
                    TextColumn::make('number')->label('#')->rowIndex(),
                    TextColumn::make('response_limit')->label(__('feedback.daily_limit_per_day'))
                        ->formatStateUsing(fn (int $state): string => __('feedback.daily_limit_count', ['count' => $state])),
                    TextColumn::make('editor.name')->label(__('feedback.daily_limit_editor'))->placeholder('—')
                        ->description(fn (DailyFeedbackLimit $record): string => $record->created_at->setTimezone('Asia/Vientiane')->format('d/m/Y H:i')),
                ])
                ->defaultSort('id', 'desc')
                ->filters([static::historyDateFilter('created_at')], layout: FiltersLayout::AboveContent)
                ->paginated([10, 25, 50]);
        }

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lastChange.staff'))
            ->heading(__('feedback.rules_history'))
            ->headerActions([
                RuleEditorAction::make('draft', creating: true)->label(__('feedback.new_rules'))->icon('heroicon-o-plus'),
            ])
            ->columns([
                TextColumn::make('number')->label('#')->rowIndex(),
                TextColumn::make('schedule')->label(__('feedback.popup_schedule'))
                    ->state(fn (RuleVersion $record): string => static::scheduleLabel($record->rules))->wrap(),
                TextColumn::make('duration')->label(__('feedback.rules_duration'))
                    ->state(fn (RuleVersion $record): string => static::durationLabel($record->rules)),
                TextColumn::make('popup')->label(__('feedback.status'))
                    ->state(fn (RuleVersion $record): string => ($record->rules['channels']['auto_prompt'] ?? false) ? 'on' : 'off')
                    ->formatStateUsing(fn (string $state): string => __('feedback.rules_states.'.$state))
                    ->badge()->color(fn (string $state): string => $state === 'on' ? 'success' : 'gray')
                    ->description(fn (RuleVersion $record): string => 'v'.$record->version.' · '.__('feedback.rules_states.'.$record->status)),
                TextColumn::make('transactions')->label(__('feedback.transaction_type'))
                    ->state(fn (RuleVersion $record): string => static::transactionLabels($record->rules))->placeholder('—')->wrap(),
                TextColumn::make('operator')->label(__('feedback.operator'))
                    ->state(fn (RuleVersion $record): string => $record->lastChange?->staff?->name ?? '—')
                    ->description(fn (RuleVersion $record): ?string => ($record->lastChange?->created_at ?? $record->updated_at)?->setTimezone('Asia/Vientiane')->format('d/m/Y H:i')),
            ])
            ->defaultSort('version', 'desc')
            ->filters([static::historyDateFilter('updated_at')], layout: FiltersLayout::AboveContent)
            ->paginated([10, 25, 50])
            ->recordActions([
                Action::make('details')->label(__('feedback.details'))->modalContent(function (RuleVersion $record) {
                    Access::check(auth()->user(), 'rules.read');

                    return view('filament.partials.popup-rules', ['rules' => PopupPolicy::normalizeRules($record->rules), 'legacy' => ! isset($record->rules['transaction_rules'])]);
                })->modalSubmitAction(false),
                RuleEditorAction::make('editDraft')->label(__('feedback.edit_draft'))
                    ->visible(fn (RuleVersion $record): bool => $record->status === 'draft'),
                RuleEditorAction::make('newVersion')->label(__('feedback.new_version'))
                    ->visible(fn (RuleVersion $record): bool => $record->status !== 'draft'),
                Action::make('publish')->label(__('feedback.publish'))
                    ->visible(fn (RuleVersion $record): bool => in_array($record->status, ['draft', 'submitted', 'approved'], true) && auth()->user()->can('changes.publish'))
                    ->fillForm(fn (RuleVersion $record): array => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision')])->requiresConfirmation()
                    ->action(fn (RuleVersion $record, array $data) => app(Changes::class)->transition(auth()->user(), 'rules', $record->id, (int) $data['revision'], 'publish')),
            ]);
    }

    private static function historyDateFilter(string $column): Filter
    {
        return Filter::make('edited_at')->schema([
            FilterFields::dateTime('from', __('feedback.from')),
            FilterFields::dateTime('until', __('feedback.until')),
        ])->columns(2)->query(fn (Builder $query, array $data): Builder => ReportFilters::applyDateRange($query, $data, $column));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRules::route('/'),
            'create' => Pages\EditRule::route('/create'),
            'edit' => Pages\EditRule::route('/{record}/edit'),
        ];
    }
}
