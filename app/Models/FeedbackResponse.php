<?php

namespace App\Models;

class FeedbackResponse extends Record
{
    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'customer_name' => 'encrypted', 'customer_phone' => 'encrypted', 'gender' => 'encrypted'];
    }

    public $timestamps = false;

    protected $hidden = ['customer_name', 'customer_phone', 'gender'];

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
