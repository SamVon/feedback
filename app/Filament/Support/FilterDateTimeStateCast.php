<?php

namespace App\Filament\Support;

use App\Application\ReportFilters;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

final class FilterDateTimeStateCast implements StateCast
{
    public function __construct(private bool $until) {}

    public function get(mixed $state): ?string
    {
        return blank($state) ? null : ReportFilters::dateInput($state, $this->until);
    }

    public function set(mixed $state): ?string
    {
        return $this->get($state);
    }
}
