<?php

namespace App\Models;

class IdempotencyRecord extends Record
{
    protected function casts(): array
    {
        return ['response' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public $timestamps = false;
}
