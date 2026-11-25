<?php

namespace App\Filament\Support;

use App\Application\PopupPolicy;
use App\Filament\Resources\RuleResource;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class RuleFields
{
    public static function make(bool $dateOnly): array
    {
        $start = $dateOnly
            ? DatePicker::make('popup_schedule.starts_at')->displayFormat('d/m/Y')->format('Y-m-d')->default(fn () => now('Asia/Vientiane')->toDateString())
            : DateTimePicker::make('popup_schedule.starts_at')->displayFormat('d/m/Y H:i')->format('Y-m-d\\TH:i:s\\Z')->seconds(false);
        $end = $dateOnly
            ? DatePicker::make('popup_schedule.ends_at')->displayFormat('d/m/Y')->format('Y-m-d')->default(fn () => now('Asia/Vientiane')->addDays(30)->toDateString())
            : DateTimePicker::make('popup_schedule.ends_at')->displayFormat('d/m/Y H:i')->format('Y-m-d\\TH:i:s\\Z')->seconds(false);
        $dateRequired = fn (Get $get): bool => $dateOnly && (bool) $get('channels.auto_prompt');

        return [
            Section::make(__('feedback.rules_form_period'))->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    $start->label(__('feedback.starts_at'))->native(false)->timezone('Asia/Vientiane')->required($dateRequired)->live(),
                    $end->label(__('feedback.until'))->native(false)->timezone('Asia/Vientiane')->required($dateRequired)->after('popup_schedule.starts_at')->live(),
                    TextEntry::make('duration')->label(__('feedback.rules_duration'))->state(static function (Get $get): string {
                        try {
                            return RuleResource::durationLabel(['popup_schedule' => [
                                'starts_at' => $get('popup_schedule.starts_at'),
                                'ends_at' => $get('popup_schedule.ends_at'),
                            ]]);
                        } catch (InvalidFormatException) {
                            return '—';
                        }
                    })->extraAttributes(['class' => 'eg-rules-duration']),
                ])->columnSpanFull(),
                Toggle::make('channels.auto_prompt')->label(__('feedback.rules_states.on'))
                    ->helperText(__('feedback.rules_form_switch_hint'))->onColor('info')->default(true)->live(),
                CheckboxList::make('enabled_transaction_types')->label(__('feedback.rules_form_transactions'))
                    ->options(__('feedback.rules_transaction_names'))
                    ->default(PopupPolicy::CUSTOMER_TRANSACTION_TYPES)
                    ->disableOptionWhen(fn (string $value): bool => ! in_array($value, PopupPolicy::CUSTOMER_TRANSACTION_TYPES, true))
                    ->required($dateRequired)->columns(['default' => 1, 'sm' => 2])
                    ->helperText(__('feedback.rules_form_transaction_hint'))
                    ->extraAttributes(['class' => 'eg-rules-transaction-choices']),
            ])->columnSpanFull(),
            Section::make(__('feedback.rules_advanced'))->collapsible()->collapsed()->schema([
                TextInput::make('rate_limit')->label(__('feedback.submission_rate_limit'))->integer()->minValue(1)->maxValue(1000)->required()->default(5)->helperText(__('feedback.popup_rate_hint')),
                TextInput::make('rate_window_seconds')->label(__('feedback.submission_rate_window'))->integer()->minValue(60)->maxValue(86400)->required()->default(3600),
                TextInput::make('invitation_ttl_seconds')->label(__('feedback.invitation_lifetime'))->integer()->minValue(60)->maxValue(604800)->required()->default(86400),
            ])->columns(3)->columnSpanFull(),
        ];
    }
}
