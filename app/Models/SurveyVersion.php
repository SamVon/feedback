<?php

namespace App\Models;

use App\Models\Concerns\ImmutablePublished;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SurveyVersion extends Record
{
    use ImmutablePublished;

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'channels' => 'array', 'content' => 'array'];
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    public function lastEdit(): HasOne
    {
        return $this->hasOne(AuditEvent::class, 'entity_id')->ofMany(['created_at' => 'max', 'id' => 'max'], fn ($query) => $query->where('entity_type', 'survey')->where('operation', 'survey.draft'));
    }
}
