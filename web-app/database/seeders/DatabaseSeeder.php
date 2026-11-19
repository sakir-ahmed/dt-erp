<?php

namespace Database\Seeders;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = array_map(
            fn (WorkspacePermission $permission) => Permission::findOrCreate($permission->value, 'web'),
            WorkspacePermission::cases(),
        );

        Role::findOrCreate(WorkspaceRole::Administrator->value, 'web')->givePermissionTo($permissions);
        Role::findOrCreate(WorkspaceRole::Staff->value, 'web')->givePermissionTo(WorkspacePermission::ViewProducts->value);
    }
}
