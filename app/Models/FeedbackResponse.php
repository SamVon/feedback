<?php

namespace App\Models;

class FeedbackResponse extends Record
{
    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public $timestamps = false;

    public function answers()
    {
        return $this->hasMany(FeedbackAnswer::class);
    }

    public function version()
    {
        return $this->belongsTo(SurveyVersion::class, 'survey_version_id');
    }

    public function context()
    {
        return $this->belongsTo(TransactionContext::class, 'transaction_context_id');
    }
}
