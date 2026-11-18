<?php

namespace App\Integrations;

interface StaffIdentityProvider
{
    /** Verify signature, issuer, audience, expiry and revocation, then return an allowlisted permission mapping. */
    public function verify(string $credential): VerifiedStaffIdentity;
}
