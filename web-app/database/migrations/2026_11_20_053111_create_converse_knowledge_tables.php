<?php

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_knowledge_collections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_demo')->default(false);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('cv_knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('cv_knowledge_collections')->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('cv_knowledge_entries')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('language', 12);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('amount_type', 16)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('public')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });

        $permission = Permission::findOrCreate(WorkspacePermission::ManageConverseKnowledge->value, 'web');
        Role::query()->where('guard_name', 'web')->where('name', WorkspaceRole::Administrator->value)
            ->first()?->givePermissionTo($permission);
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', WorkspacePermission::ManageConverseKnowledge->value)->first()?->delete();
        Schema::dropIfExists('cv_knowledge_entries');
        Schema::dropIfExists('cv_knowledge_collections');
    }
};
