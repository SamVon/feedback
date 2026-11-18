<?php

namespace App\Models;

class FeedbackAnswer extends Record
{
    protected function casts(): array
    {
        return ['rating_value' => 'integer'];
    }

    public $timestamps = false;

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    public function options()
    {
        return $this->belongsToMany(QuestionOption::class, 'answer_options');
    }
}
