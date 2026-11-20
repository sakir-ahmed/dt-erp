<?php

namespace Database\Seeders;

use App\Actions\LocalQuickSignIn;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocalQuickSignInSeeder extends Seeder
{
    public function run(): void
    {
        if (! User::localQuickSignInEnabled()) {
            return;
        }

        $this->call(DatabaseSeeder::class);

        foreach (WorkspaceRole::cases() as $role) {
            DB::transaction(function () use ($role) {
                $email = LocalQuickSignIn::email($role);
                $user = User::query()->firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => 'Local '.ucfirst($role->value),
                        'password' => LocalQuickSignIn::fixturePassword($email),
                        'is_demo' => false,
                    ],
                );

                if ($user->wasRecentlyCreated) {
                    $user->assignRole($role->value);
                }
            });
        }
    }
}
