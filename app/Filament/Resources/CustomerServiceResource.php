<?php

namespace App\Filament\Resources;

use App\Application\Changes;
use App\Application\ChannelPolicy;
use App\Application\PopupPolicy;
use App\Filament\Support\CustomerServiceFields;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
        $schema = [Hidden::make('revision'), ...CustomerServiceFields::make()];
        $fill = fn ($record) => ['revision' => $record->revision, 'customer_service' => ChannelPolicy::customerService($record->rules)];

        return parent::table($table)->headerActions([])->columns([
            TextColumn::make('channel_name')->label(__('feedback.name'))->state(fn ($record) => ChannelPolicy::customerService($record->rules)['name'][app()->getLocale()]),
            TextColumn::make('channel_status')->label(__('feedback.customer_service_enabled'))->state(fn ($record) => ChannelPolicy::customerService($record->rules)['enabled'] ? __('feedback.popup_yes') : __('feedback.popup_no'))->badge(),
            TextColumn::make('version')->sortable(), TextColumn::make('revision'), TextColumn::make('status')->badge(), TextColumn::make('published_at')->dateTime(),
        ])->recordActions([
            Action::make('newVersion')->label(__('feedback.new_version'))->visible(fn () => auth()->user()->can('rules.edit'))->fillForm($fill)->schema($schema)->action(function ($record, array $data) {
                return app(Changes::class)->ruleDraft(auth()->user(), array_replace(PopupPolicy::normalizeRules($record->rules), ['customer_service' => $data['customer_service']]));
            }),
            Action::make('editDraft')->label(__('feedback.edit_draft'))->visible(fn ($record) => $record->status === 'draft' && auth()->user()->can('rules.edit'))->fillForm($fill)->schema($schema)->action(fn ($record, array $data) => app(Changes::class)->ruleDraft(auth()->user(), array_replace(PopupPolicy::normalizeRules($record->rules), ['customer_service' => $data['customer_service']]), $record->id, (int) $data['revision'])),
            Action::make('submit')->label(__('feedback.submit'))->visible(fn ($record) => $record->status === 'draft' && auth()->user()->can('rules.edit'))->fillForm(fn ($record) => ['revision' => $record->revision])->schema([Hidden::make('revision')])->requiresConfirmation()->action(fn ($record, array $data) => app(Changes::class)->transition(auth()->user(), 'rules', $record->id, (int) $data['revision'], 'submit')),
        ])->description(__('feedback.customer_service_scope'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCustomerServices::route('/')];
    }
}
