<?php

namespace App\Application;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class Access
{
    public static function check(User $user, string $permission): void
    {
        Gate::forUser($user)->authorize($permission);
    }
}
