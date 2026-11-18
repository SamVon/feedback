<?php

namespace App\Filament\Resources;

use App\Application\Access;
use App\Application\Changes;
use App\Application\PopupPolicy;
use App\Filament\Support\ReadOnlyResource;
use App\Filament\Support\RuleFields;
use App\Models\RuleVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RuleResource extends ReadOnlyResource
{
    protected static ?string $model = RuleVersion::class;

    protected static string $permission = 'rules.read';

    protected static ?string $navigationLabel = 'rules';

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('version')->sortable(), TextColumn::make('revision'), TextColumn::make('transaction_policy')->label(__('feedback.popup_transaction_rules'))->state(fn ($record) => collect(PopupPolicy::normalizeRules($record->rules)['transaction_rules'])->map(fn (array $rule): string => $rule['transaction_type'].' · '.($rule['enabled'] ? '3 / 30d' : __('feedback.popup_no')))->join(', '))->wrap(), TextColumn::make('rules.popup_schedule.starts_at')->label(__('feedback.starts_at'))->dateTime()->timezone('Asia/Vientiane'), TextColumn::make('rules.popup_schedule.ends_at')->label(__('feedback.ends_at'))->dateTime()->timezone('Asia/Vientiane'), TextColumn::make('status')->badge(), TextColumn::make('published_at')->dateTime()])->defaultSort('version', 'desc')->headerActions([
            Action::make('draft')->label(__('feedback.new_rules'))->visible(fn () => auth()->user()->can('rules.edit'))->schema(RuleFields::make())->action(fn (array $data) => app(Changes::class)->ruleDraft(auth()->user(), $data)),
        ])->recordActions([
            Action::make('details')->label(__('feedback.details'))->modalContent(function ($record) {
                Access::check(auth()->user(), 'rules.read');

                return view('filament.partials.popup-rules', ['rules' => PopupPolicy::normalizeRules($record->rules), 'legacy' => ! isset($record->rules['transaction_rules'])]);
            })->modalSubmitAction(false),
            Action::make('editDraft')->label(__('feedback.edit_draft'))->visible(fn ($record) => $record->status === 'draft' && auth()->user()->can('rules.edit'))->fillForm(fn ($record) => PopupPolicy::normalizeRules($record->rules) + ['revision' => $record->revision])->schema(RuleFields::make())->action(fn ($record, array $data) => app(Changes::class)->ruleDraft(auth()->user(), $data, $record->id, (int) $data['revision'])),
            Action::make('newVersion')->label(__('feedback.new_version'))->visible(fn () => auth()->user()->can('rules.edit'))->fillForm(fn ($record) => PopupPolicy::normalizeRules($record->rules))->schema(RuleFields::make())->action(fn (array $data) => app(Changes::class)->ruleDraft(auth()->user(), $data)),
            Action::make('submit')->label(__('feedback.submit'))->visible(fn ($record) => $record->status === 'draft' && auth()->user()->can('rules.edit'))->fillForm(fn ($record) => ['revision' => $record->revision])->schema([Hidden::make('revision')])->requiresConfirmation()->action(fn ($record, array $data) => app(Changes::class)->transition(auth()->user(), 'rules', $record->id, (int) $data['revision'], 'submit')),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRules::route('/')];
    }
}
