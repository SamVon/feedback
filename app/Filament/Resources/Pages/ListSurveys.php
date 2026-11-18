<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\SurveyResource;
use Filament\Resources\Pages\ListRecords;

class ListSurveys extends ListRecords
{
    protected static string $resource = SurveyResource::class;
}
