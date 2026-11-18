<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Record
{
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor');
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public $timestamps = false;
}
