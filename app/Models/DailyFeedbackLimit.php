<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyFeedbackLimit extends Model
{
    public const DEFAULT_LIMIT = 3;

    protected $fillable = ['response_limit', 'editor_id'];

    protected function casts(): array
    {
        return ['response_limit' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public static function current(): ?self
    {
        return static::latest('id')->first();
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }
}
