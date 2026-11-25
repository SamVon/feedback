<?php

namespace App\Filament\Support;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;

final class CustomerServiceFields
{
    public static function make(): array
    {
        return [
            FileUpload::make('customer_service.icon_path')->label(__('feedback.customer_service_icon'))
                ->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(1024)
                ->disk('public')->directory('customer-service-icons')->visibility('public')
                ->imageCropAspectRatio('1:1')->imagePreviewHeight('110')->panelAspectRatio('1:1')
                ->placeholder(__('feedback.customer_service_upload'))
                ->helperText(__('feedback.customer_service_icon_hint'))->extraFieldWrapperAttributes(['class' => 'eg-cs-icon-upload']),
            Select::make('customer_service.icon')->label(__('feedback.customer_service_standard_icon'))
                ->options(['star' => __('feedback.customer_service_icon_star'), 'chat' => __('feedback.customer_service_icon_chat'), 'headset' => __('feedback.customer_service_icon_headset')])
                ->required()->default('star')->visible(fn (Get $get): bool => ! filled($get('customer_service.icon_path')))->dehydratedWhenHidden(),
            Group::make([
                TextInput::make('customer_service.name.lo')->label(__('feedback.name').' · '.__('feedback.other_question_lao'))->required()->maxLength(100),
                TextInput::make('customer_service.name.en')->label(__('feedback.name').' · English')->required()->maxLength(100),
            ])->columns(['default' => 1, 'sm' => 2])->extraAttributes(['class' => 'eg-cs-language-pair']),
            Group::make([
                Textarea::make('customer_service.description.lo')->label(__('feedback.description').' · '.__('feedback.other_question_lao'))->maxLength(500)->rows(2),
                Textarea::make('customer_service.description.en')->label(__('feedback.description').' · English')->maxLength(500)->rows(2),
            ])->columns(['default' => 1, 'sm' => 2])->extraAttributes(['class' => 'eg-cs-language-pair']),
            Toggle::make('customer_service.enabled')->label(__('feedback.rules_enable_feedback_page'))->helperText(__('feedback.customer_service_close_hint')),
        ];
    }
}
