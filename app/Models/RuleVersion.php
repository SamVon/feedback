<?php

namespace App\Models;

use App\Models\Concerns\ImmutablePublished;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RuleVersion extends Record
{
    use ImmutablePublished;

    protected function casts(): array
    {
        return ['rules' => 'array', 'published_at' => 'immutable_datetime'];
    }

    public function lastChange(): HasOne
    {
        return $this->hasOne(AuditEvent::class, 'entity_id')
            ->ofMany(['created_at' => 'max', 'id' => 'max'], fn ($query) => $query->where('entity_type', 'rules'));
    }
}
