<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

class LocalAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('feedback.staff_auth') !== 'local') {
            return;
        }

        $email = config('feedback.local_admin.email');
        $password = config('feedback.local_admin.password');
        if (blank($email) && blank($password)) {
            return;
        }

        $data = Validator::make(compact('email', 'password'), [
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8',
        ])->validate();

        User::firstOrCreate(['email' => $data['email']], [
            'name' => 'Administrator',
            'password' => $data['password'],
            'active' => true,
            'permissions' => config('feedback.permissions'),
        ]);
    }
}
