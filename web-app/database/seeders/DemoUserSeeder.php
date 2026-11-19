<?php

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! User::demoLoginEnabled()) {
            return;
        }

        $this->call(DatabaseSeeder::class);

        foreach (WorkspaceRole::cases() as $role) {
            DB::transaction(function () use ($role) {
                $user = User::firstOrNew(['email' => "demo-{$role->value}@erp.example.invalid"]);

                if ($user->exists && ! $user->getAttribute('is_demo')) {
                    return;
                }

                if (! $user->exists) {
                    $user->forceFill([
                        'name' => 'Demo '.ucfirst($role->value),
                        'password' => Str::random(48),
                        'is_demo' => true,
                    ])->save();
                }

                $user->assignRole($role->value);
            });
        }
    }
}
