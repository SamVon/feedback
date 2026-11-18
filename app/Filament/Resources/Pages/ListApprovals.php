<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\ApprovalResource;
use Filament\Resources\Pages\ListRecords;

class ListApprovals extends ListRecords
{
    protected static string $resource = ApprovalResource::class;
}
