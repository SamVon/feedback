<?php

namespace App\Models;

class TransactionContext extends Record
{
    protected function casts(): array
    {
        return ['transaction_occurred_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime'];
    }

    public $timestamps = false;
}
