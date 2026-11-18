<?php

namespace App\Filament\Support;

use App\Application\ChannelPolicy;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

final class RuleFields
{
    public static function make(): array
    {
        return [
            Hidden::make('revision'),
            Hidden::make('customer_service')->default(fn () => ChannelPolicy::customerService([])),
            Section::make(__('feedback.popup_schedule'))->description(__('feedback.schedule_hint'))->schema([
                DateTimePicker::make('popup_schedule.starts_at')->label(__('feedback.starts_at'))->timezone('Asia/Vientiane')->format('Y-m-d\\TH:i:s\\Z')->seconds(false),
                DateTimePicker::make('popup_schedule.ends_at')->label(__('feedback.ends_at'))->timezone('Asia/Vientiane')->format('Y-m-d\\TH:i:s\\Z')->seconds(false)->after('popup_schedule.starts_at'),
            ])->columns(2)->columnSpanFull(),
            Toggle::make('channels.auto_prompt')->label('Auto prompt')->default(true),
            Section::make(__('feedback.always_on_policy'))->description(__('feedback.always_on_proposal_hint'))->schema([
                Toggle::make('channels.always_on')->label('Always on')->default(true),
                Text::make(__('feedback.always_on_proposal')),
                Hidden::make('always_on_frequency')->default(fn () => ChannelPolicy::frequencyProposal()),
            ])->columnSpanFull(),
            Repeater::make('transaction_rules')->label(__('feedback.popup_transaction_rules'))
                ->helperText(__('feedback.popup_policy_hint'))
                ->maxItems(50)->default([
                    ['transaction_type' => 'BUY_GOLD', 'enabled' => true, 'max_responses' => 3, 'cooldown_days' => 30, 'reset_policy' => 'pending_confirmation'],
                    ['transaction_type' => 'SALE_GOLD', 'enabled' => true, 'max_responses' => 3, 'cooldown_days' => 30, 'reset_policy' => 'pending_confirmation'],
                ])->schema([
                    TextInput::make('transaction_type')->label(__('feedback.transaction_type'))->required()->maxLength(50),
                    Toggle::make('enabled')->label(__('feedback.popup_enabled'))->default(true),
                    TextInput::make('max_responses')->label(__('feedback.popup_max_responses'))->numeric()->default(3)->readOnly()->required(),
                    TextInput::make('cooldown_days')->label(__('feedback.popup_cooldown_days'))->numeric()->default(30)->readOnly()->required(),
                    Select::make('reset_policy')->label(__('feedback.popup_reset_policy'))->options(['pending_confirmation' => __('feedback.popup_pending')])->default('pending_confirmation')->disabled()->dehydrated()->required()->helperText(__('feedback.popup_reset_hint')),
                ])->columns(2)->columnSpanFull(),
            TextInput::make('rate_limit')->label(__('feedback.submission_rate_limit'))->numeric()->required()->default(5)->helperText(__('feedback.popup_rate_hint')),
            TextInput::make('rate_window_seconds')->label(__('feedback.submission_rate_window'))->numeric()->required()->default(3600),
            TextInput::make('invitation_ttl_seconds')->label(__('feedback.invitation_lifetime'))->numeric()->required()->default(86400),
        ];
    }
}
