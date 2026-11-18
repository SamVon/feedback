<?php

namespace App\Models;

class CohortSnapshot extends Record
{
    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function members()
    {
        return $this->hasMany(CohortMember::class);
    }
}
