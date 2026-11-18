<?php

namespace App\Filament\Support;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class ReadOnlyResource extends Resource
{
    protected static string $permission;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(static::$permission) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('feedback.'.(in_array(static::$navigationLabel, ['approvals', 'audit'], true) ? 'nav_administration' : 'nav_customer_service'));
    }

    public static function getNavigationLabel(): string
    {
        return __('feedback.'.static::$navigationLabel);
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(static::canViewAny(), 403);

        return parent::getEloquentQuery();
    }
}
