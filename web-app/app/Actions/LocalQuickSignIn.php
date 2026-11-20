<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class LocalQuickSignIn
{
    public const array ROLES = [WorkspaceRole::Administrator, WorkspaceRole::Staff];

    public static function demoEnabled(): bool
    {
        return ! app()->isProduction() && config('erp.demo_login') === true;
    }

    public static function email(WorkspaceRole $role): string
    {
        return "local-{$role->value}@erp.example.invalid";
    }

    /**
     * Prove the seeder created the account using its existing password hash.
     */
    public static function fixturePassword(string $email, ?string $key = null): string
    {
        return hash_hmac('sha256', 'local-quick-sign-in:'.$email, $key ?? config('app.key'));
    }

    /** @return Collection<int, User> */
    public static function eligibleAccounts(?int $userId = null): Collection
    {
        return User::query()
            ->where('is_demo', false)
            ->whereIn('email', array_map(self::email(...), self::ROLES))
            ->when($userId !== null, fn ($query) => $query->whereKey($userId))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => password_verify(self::fixturePassword($user->email), $user->password));
    }
}
