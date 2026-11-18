<?php

namespace App\Integrations;

final readonly class VerifiedStaffIdentity
{
    public function __construct(public string $externalId, public string $name, public array $permissions, public \DateTimeImmutable $expiresAt) {}
}
