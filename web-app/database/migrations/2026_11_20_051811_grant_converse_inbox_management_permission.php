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
        $permission = Permission::findOrCreate(WorkspacePermission::ManageConverseInbox->value, 'web');
        Role::query()->where('guard_name', 'web')
            ->whereIn('name', [WorkspaceRole::Administrator->value, WorkspaceRole::Staff->value])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', WorkspacePermission::ManageConverseInbox->value)->first()?->delete();
    }
};
