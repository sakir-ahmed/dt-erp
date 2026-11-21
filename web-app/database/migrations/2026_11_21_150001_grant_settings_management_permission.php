<?php

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate(WorkspacePermission::ManageSettings->value, 'web');
        Role::query()->where('guard_name', 'web')
            ->where('name', WorkspaceRole::Administrator->value)
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', WorkspacePermission::ManageSettings->value)->first()?->delete();
    }
};
