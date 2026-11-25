<?php

namespace App\Filament\Support;

use Filament\Forms\Components\DateTimePicker;

class FilterDateTimePicker extends DateTimePicker
{
    /** Filters store local wall time; ReportFilters converts to UTC once when querying. */
    public function getDefaultStateCasts(): array
    {
        return [new FilterDateTimeStateCast($this->getName() === 'until')];
    }
}
