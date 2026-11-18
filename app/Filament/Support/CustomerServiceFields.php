<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

final class CustomerServiceFields
{
    public static function make(): array
    {
        return [
            Toggle::make('customer_service.enabled')->label(__('feedback.customer_service_enabled'))->helperText(__('feedback.customer_service_close_hint')),
            Select::make('customer_service.icon')->label(__('feedback.icon'))->options(['star' => '★ Star', 'chat' => 'Chat', 'headset' => 'Headset'])->required()->helperText(__('feedback.safe_icon_hint')),
            TextInput::make('customer_service.name.en')->label(__('feedback.name').' · English')->required()->maxLength(100),
            TextInput::make('customer_service.name.lo')->label(__('feedback.name').' · ລາວ')->required()->maxLength(100),
            Textarea::make('customer_service.description.en')->label(__('feedback.description').' · English')->maxLength(500),
            Textarea::make('customer_service.description.lo')->label(__('feedback.description').' · ລາວ')->maxLength(500),
        ];
    }
}
