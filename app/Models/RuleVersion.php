<?php

namespace App\Models;

use App\Models\Concerns\ImmutablePublished;

class RuleVersion extends Record
{
    use ImmutablePublished;

    protected function casts(): array
    {
        return ['rules' => 'array', 'published_at' => 'immutable_datetime'];
    }
}
