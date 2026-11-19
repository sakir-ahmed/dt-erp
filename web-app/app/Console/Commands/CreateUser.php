<?php

namespace App\Console\Commands;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class CreateUser extends Command
{
    protected $signature = 'erp:user:create {--administrator : Grant the administrator role}';

    protected $description = 'Create an ERP user interactively';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command in an interactive terminal.');

            return self::FAILURE;
        }

        $roleName = $this->option('administrator') ? WorkspaceRole::Administrator : WorkspaceRole::Staff;
        $role = Role::query()->where('name', $roleName->value)->where('guard_name', 'web')->first();

        if ($role === null) {
            $this->error('Run php artisan db:seed to initialize ERP roles.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => trim((string) $this->ask('Name')),
            'email' => trim((string) $this->ask('Email')),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($validator, $role) {
            User::create($validator->safe()->only(['name', 'email', 'password']))->assignRole($role);
        });

        $this->info('ERP user created.');

        return self::SUCCESS;
    }
}
