<?php

namespace App\Models;

class FeedbackInvitation extends Record
{
    protected function casts(): array
    {
        return ['eligible_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public $timestamps = false;

    public function context()
    {
        return $this->belongsTo(TransactionContext::class, 'transaction_context_id');
    }
}
