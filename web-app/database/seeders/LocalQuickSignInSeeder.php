<?php

namespace Database\Seeders;

use App\Actions\LocalQuickSignIn;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocalQuickSignInSeeder extends Seeder
{
    public static function canSeed(bool $refresh = false): bool
    {
        return filled(config('app.key'))
            && (User::localQuickSignInEnabled() || ($refresh && LocalQuickSignIn::demoEnabled()));
    }

    public function run(?string $previousKey = null): void
    {
        if (! self::canSeed($previousKey !== null)) {
            return;
        }

        if ($previousKey !== null) {
            $this->refreshFixtures($previousKey);

            return;
        }

        $this->call(DatabaseSeeder::class);

        foreach (LocalQuickSignIn::ROLES as $role) {
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

    private function refreshFixtures(string $previousKey): void
    {
        DB::transaction(function () use ($previousKey) {
            foreach (LocalQuickSignIn::ROLES as $role) {
                $email = LocalQuickSignIn::email($role);
                $user = User::query()->where('email', $email)->where('is_demo', false)->lockForUpdate()->first();
                $password = LocalQuickSignIn::fixturePassword($email);

                if ($user === null || password_verify($password, $user->password)) {
                    continue;
                }

                if (password_verify(LocalQuickSignIn::fixturePassword($email, $previousKey), $user->password)) {
                    $user->update(['password' => $password]);
                }
            }
        });
    }
}
