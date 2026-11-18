<?php

namespace App\Models;

class Survey extends Record
{
    protected function casts(): array
    {
        return [];
    }

    public function versions()
    {
        return $this->hasMany(SurveyVersion::class);
    }
}
