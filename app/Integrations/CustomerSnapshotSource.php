<?php

namespace App\Integrations;

interface CustomerSnapshotSource
{
    /** An authorized, consistent manifest with account IDs and only approved identity fields. */
    public function manifest(): array;

    /** Each batch must be bound to the immutable manifest, never live offset pagination. */
    public function batches(string $externalSnapshotId): iterable;
}
