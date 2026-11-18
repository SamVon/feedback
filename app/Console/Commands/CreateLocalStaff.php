<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateLocalStaff extends Command
{
    protected $signature = 'feedback:create-local-staff {email} {--name=Local staff} {--permissions=}';

    protected $description = 'Create an explicitly authorized local development staff account with an interactive password.';

    public function handle(): int
    {
        if (! app()->environment('local') || config('feedback.staff_auth') !== 'local') {
            $this->error('Only available with APP_ENV=local and FEEDBACK_STAFF_AUTH=local.');

            return self::FAILURE;
        }
        $permissions = array_filter(explode(',', (string) $this->option('permissions')));
        if (! $permissions || array_diff($permissions, config('feedback.permissions'))) {
            $this->error('Pass explicit comma-separated permissions: '.implode(',', config('feedback.permissions')));

            return self::FAILURE;
        }
        $password = $this->secret('Password (minimum 14 characters)');
        $data = ['email' => $this->argument('email'), 'password' => $password];
        $validator = Validator::make($data, ['email' => 'required|email|unique:users,email', 'password' => 'required|string|min:14']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        User::create($data + ['name' => $this->option('name'), 'permissions' => array_values($permissions), 'active' => true]);
        $this->info('Local staff account created.');

        return self::SUCCESS;
    }
}
