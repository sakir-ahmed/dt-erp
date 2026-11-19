<?php

namespace App\Actions\Users;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveStaffUser
{
    /** @param array{name: string, email: string, password?: string|null} $attributes */
    public function __invoke(array $attributes, ?User $user = null): void
    {
        DB::transaction(function () use ($attributes, $user) {
            if ($user === null) {
                User::create($attributes)->assignRole(WorkspaceRole::Staff->value);

                return;
            }

            $staff = User::query()->whereKey($user->getKey())->where('is_demo', false)->lockForUpdate()->firstOrFail();
            abort_unless($staff->hasExactRoles([WorkspaceRole::Staff->value]), 403);

            if (empty($attributes['password'])) {
                unset($attributes['password']);
            }

            $staff->update($attributes);
        });
    }
}
