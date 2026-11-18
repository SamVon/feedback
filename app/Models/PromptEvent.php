<?php

namespace App\Models;

class PromptEvent extends Record
{
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public $timestamps = false;
}
