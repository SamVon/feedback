<?php

namespace App\Application;

use App\Models\AuditEvent;

final class Audit
{
    public static function record(string $actor, string $operation, string $type, string $id, array $details = []): void
    {
        AuditEvent::create(['actor' => $actor, 'operation' => $operation, 'entity_type' => $type, 'entity_id' => $id, 'details' => $details, 'created_at' => now()]);
    }
}
