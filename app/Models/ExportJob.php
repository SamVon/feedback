<?php

namespace App\Models;

class ExportJob extends Record
{
    protected function casts(): array
    {
        return ['filters' => 'array', 'summary' => 'array', 'table_layout' => 'array', 'snapshot_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
