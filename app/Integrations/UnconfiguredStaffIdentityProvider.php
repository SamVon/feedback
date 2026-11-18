<?php

namespace App\Integrations;

final class UnconfiguredStaffIdentityProvider implements StaffIdentityProvider
{
    public function verify(string $credential): VerifiedStaffIdentity
    {
        throw new \RuntimeException('kpv-auth-service contract is not configured.');
    }
}
