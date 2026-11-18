<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\CustomerServiceResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerServices extends ListRecords
{
    protected static string $resource = CustomerServiceResource::class;
}
