<?php

namespace App\Models;

class Question extends Record
{
    protected static function booted(): void
    {
        $guard = function ($model) {
            $version = SurveyVersion::find($model->survey_version_id);
            abort_unless(! $version || $version->status === 'draft', 409, 'Questions in non-draft versions are immutable.');
        };
        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['label' => 'array', 'enabled' => 'boolean', 'required' => 'boolean', 'rating_reasons_enabled' => 'boolean', 'rating_reasons_required' => 'boolean', 'max_choices' => 'integer'];
    }

    public $timestamps = false;

    public function options()
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position')->orderBy('id');
    }

    public function version()
    {
        return $this->belongsTo(SurveyVersion::class, 'survey_version_id');
    }
}
