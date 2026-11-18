<?php

namespace App\Policies;

use App\Models\FeedbackResponse;
use App\Models\User;

class FeedbackResponsePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('feedback.read');
    }

    public function view(User $user, FeedbackResponse $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FeedbackResponse $record): bool
    {
        return false;
    }

    public function delete(User $user, FeedbackResponse $record): bool
    {
        return false;
    }
}
