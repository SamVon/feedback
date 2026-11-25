<?php

namespace App\Filament\Resources\Pages;

use App\Application\Access;
use App\Filament\Resources\CustomerServiceResource;
use App\Models\RuleVersion;
use Filament\Resources\Pages\ListRecords;

class ListCustomerServices extends ListRecords
{
    protected static string $resource = CustomerServiceResource::class;

    protected string $view = 'filament.resources.pages.list-customer-services';

    public function getTitle(): string
    {
        return 'Customer Service';
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function hasUnpublishedSettings(): bool
    {
        Access::check(auth()->user(), 'rules.read');
        $version = RuleVersion::orderByDesc('version')->first();

        return $version !== null && $version->status !== 'published';
    }
}
