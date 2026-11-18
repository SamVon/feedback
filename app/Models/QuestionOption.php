<?php

namespace App\Models;

class QuestionOption extends Record
{
    protected static function booted(): void
    {
        $guard = function ($model) {
            $version = Question::find($model->question_id)?->version;
            abort_unless(! $version || $version->status === 'draft', 409, 'Questions in non-draft versions are immutable.');
        };
        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['label' => 'array', 'rating_value' => 'integer', 'position' => 'integer'];
    }

    public $timestamps = false;
}
