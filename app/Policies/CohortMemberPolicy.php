<?php

namespace App\Policies;

use App\Models\CohortMember;
use App\Models\User;

class CohortMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.read');
    }

    public function view(User $user, CohortMember $record): bool
    {
        return $this->viewAny($user);
    }

    public function viewIdentity(User $user, CohortMember $record): bool
    {
        return $user->can('customers.read') && $user->can('customer_identity.read');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CohortMember $record): bool
    {
        return false;
    }

    public function delete(User $user, CohortMember $record): bool
    {
        return false;
    }
}
