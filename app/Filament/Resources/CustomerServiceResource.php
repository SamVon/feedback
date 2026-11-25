<?php

namespace App\Filament\Resources;

use App\Application\Access;
use App\Application\Changes;
use App\Application\ChannelPolicy;
use App\Application\PopupPolicy;
use App\Filament\Support\CustomerServiceFields;
use App\Models\RuleVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerServiceResource extends RuleResource
{
    protected static ?string $navigationLabel = 'customer_service';

    protected static ?string $slug = 'customer-service';

    protected static ?int $navigationSort = 6;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    public static function getNavigationGroup(): ?string
    {
        return __('feedback.nav_administration');
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query
            ->where('rule_versions.version', RuleVersion::query()->selectRaw('MAX(version)'))->with('lastChange'))
            ->heading(__('feedback.customer_service_feedback'))
            ->columns([
                TextColumn::make('number')->label(__('feedback.customer_service_order'))->rowIndex(),
                ViewColumn::make('channel_name')->label(__('feedback.customer_service_item'))->view('filament.partials.customer-service-name'),
                TextColumn::make('description')->label(__('feedback.description'))
                    ->state(fn (RuleVersion $record): ?string => ChannelPolicy::customerService($record->rules)['description'][app()->getLocale()] ?? null)->placeholder('—')->wrap(),
                TextColumn::make('channel_status')->label(__('feedback.status'))
                    ->state(fn (RuleVersion $record): bool => ChannelPolicy::feedbackPageEnabled($record->rules))
                    ->formatStateUsing(fn (bool $state): string => __($state ? 'feedback.customer_service_open' : 'feedback.customer_service_closed'))
                    ->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('modified_at')->label(__('feedback.customer_service_modified'))
                    ->state(fn (RuleVersion $record) => $record->lastChange?->created_at ?? $record->updated_at)->dateTime('d/m/Y', timezone: 'Asia/Vientiane'),
            ])->recordActions([
                static::editorAction('newVersion', false),
                static::editorAction('editDraft', true),
                Action::make('publish')->label(__('feedback.publish'))->icon('heroicon-o-check-circle')
                    ->authorize(fn (): bool => auth()->user()->can('rules.read') && auth()->user()->can('changes.publish'))
                    ->visible(fn (RuleVersion $record): bool => in_array($record->status, ['draft', 'submitted', 'approved'], true))
                    ->fillForm(fn (RuleVersion $record): array => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision')->required()->rules(['integer'])])->requiresConfirmation()
                    ->modalHeading(__('feedback.publish'))->modalDescription(__('feedback.customer_service_publish_hint'))
                    ->modalSubmitActionLabel(__('feedback.publish'))->modalCancelActionLabel(__('feedback.other_question_cancel'))
                    ->action(function (RuleVersion $record, array $data): void {
                        Access::check(auth()->user(), 'rules.read');
                        app(Changes::class)->transition(auth()->user(), 'rules', $record->id, (int) $data['revision'], 'publish');
                        Notification::make()->success()->title(__('feedback.customer_service_published'))->send();
                    }),
            ])->recordActionsColumnLabel(__('feedback.customer_service_manage'))
            ->paginated(false)->searchable(false)->defaultSort('version', 'desc')
            ->emptyStateHeading(__('feedback.customer_service_empty'))
            ->emptyStateDescription(__('feedback.customer_service_empty_hint'));
    }

    private static function editorAction(string $name, bool $draft): Action
    {
        return Action::make($name)->label(__('feedback.customer_service_edit'))->icon('heroicon-o-pencil-square')->color('info')
            ->authorize(fn (): bool => auth()->user()->can('rules.read') && auth()->user()->can('rules.edit'))
            ->visible(fn (RuleVersion $record): bool => ($record->status === 'draft') === $draft)
            ->fillForm(fn (RuleVersion $record): array => ['revision' => $record->revision, 'customer_service' => array_replace(ChannelPolicy::customerService($record->rules), ['enabled' => ChannelPolicy::feedbackPageEnabled($record->rules)])])
            ->schema([Hidden::make('revision')->required()->rules(['integer']), ...CustomerServiceFields::make()])
            ->modalHeading(__('feedback.customer_service_edit_heading'))->modalWidth(Width::ExtraLarge)
            ->extraModalWindowAttributes(['class' => 'eg-cs-settings-modal'])
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitActionLabel(__('feedback.rating_save_draft'))->modalCancelActionLabel(__('feedback.other_question_cancel'))
            ->modalSubmitAction(fn (Action $action): Action => $action->color('primary'))
            ->modalCancelAction(fn (Action $action): Action => $action->color('gray')->outlined())
            ->modalFooterActions(fn (Action $action): array => [$action->getModalCancelAction(), $action->getModalSubmitAction()])
            ->action(function (RuleVersion $record, array $data) use ($draft): void {
                Access::check(auth()->user(), 'rules.read');
                Access::check(auth()->user(), 'rules.edit');
                abort_unless((int) $data['revision'] === $record->revision, 409, 'Stale revision; reload.');
                $rules = PopupPolicy::normalizeRules($record->rules);
                $rules['channels']['always_on'] = (bool) $data['customer_service']['enabled'];
                $rules['customer_service'] = $data['customer_service'];
                app(Changes::class)->ruleDraft(auth()->user(), $rules, $draft ? $record->id : null, $draft ? (int) $data['revision'] : null);
                Notification::make()->success()->title(__('feedback.customer_service_saved'))->body(__('feedback.customer_service_pending_hint'))->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCustomerServices::route('/')];
    }
}
