<?php

namespace App\Models\Concerns;

trait ImmutablePublished
{
    protected static function bootImmutablePublished(): void
    {
        static::updating(function ($model) {
            if ($model->getOriginal('status') === 'published') {
                throw new \LogicException('Published versions are immutable.');
            }
        });
        static::deleting(function ($model) {
            if ($model->status !== 'draft') {
                throw new \LogicException('Only draft versions can be deleted.');
            }
        });
    }
}
