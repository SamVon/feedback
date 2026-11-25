<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('rules.read') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('feedback.settings');
    }

    public function getTitle(): string
    {
        return __('feedback.settings');
    }

    public function getHeading(): ?string
    {
        return null;
    }
}
