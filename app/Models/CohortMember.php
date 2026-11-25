<?php

namespace App\Models;

class CohortMember extends Record
{
    protected function casts(): array
    {
        return ['identity_complete' => 'boolean', 'display_name' => 'encrypted', 'phone' => 'encrypted', 'app_reported_name' => 'boolean', 'app_reported_phone' => 'boolean'];
    }

    public $timestamps = false;

    protected $hidden = ['display_name', 'phone', 'app_reported_name', 'app_reported_phone'];

    public function snapshot()
    {
        return $this->belongsTo(CohortSnapshot::class, 'cohort_snapshot_id');
    }

    public function latestFilteredResponse()
    {
        return $this->belongsTo(FeedbackResponse::class, 'latest_response_id');
    }
}
