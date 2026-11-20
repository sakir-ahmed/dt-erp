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
        Schema::create('cv_channel_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('name');
            $table->string('external_id');
            $table->boolean('is_demo')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['type', 'external_id', 'is_demo']);
        });

        Schema::create('cv_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('cv_contact_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('cv_contacts')->restrictOnDelete();
            $table->foreignId('channel_account_id')->constrained('cv_channel_accounts')->restrictOnDelete();
            $table->string('external_id');
            $table->unique(['channel_account_id', 'external_id']);
        });

        Schema::create('cv_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_identity_id')->unique()->constrained('cv_contact_identities')->restrictOnDelete();
            $table->string('mode', 16)->default('ai');
            $table->string('status', 16)->default('open');
            $table->timestamp('last_message_at')->index();
            $table->timestamps();
        });

        Schema::create('cv_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('cv_conversations')->restrictOnDelete();
            $table->string('external_id')->nullable();
            $table->string('direction', 8);
            $table->string('sender_type', 16);
            $table->text('body');
            $table->timestamp('occurred_at');
            $table->unique(['conversation_id', 'external_id']);
            $table->index(['conversation_id', 'occurred_at', 'id']);
        });

        Schema::create('cv_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->char('digest', 64);
            $table->longText('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['provider', 'digest']);
        });

        Schema::create('cv_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->json('changes');
            $table->timestamp('created_at');
        });

        $manageChannels = Permission::findOrCreate(WorkspacePermission::ManageConverseChannels->value, 'web');
        Role::query()->where('guard_name', 'web')->where('name', WorkspaceRole::Administrator->value)
            ->first()?->givePermissionTo($manageChannels);

        $permission = Permission::findOrCreate(WorkspacePermission::ViewConverseInbox->value, 'web');

        Role::query()->where('guard_name', 'web')
            ->whereIn('name', [WorkspaceRole::Administrator->value, WorkspaceRole::Staff->value])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')
            ->whereIn('name', [WorkspacePermission::ViewConverseInbox->value, WorkspacePermission::ManageConverseChannels->value])
            ->get()->each(fn (Permission $permission) => $permission->delete());
        Schema::dropIfExists('cv_audit_events');
        Schema::dropIfExists('cv_webhook_events');
        Schema::dropIfExists('cv_messages');
        Schema::dropIfExists('cv_conversations');
        Schema::dropIfExists('cv_contact_identities');
        Schema::dropIfExists('cv_contacts');
        Schema::dropIfExists('cv_channel_accounts');
    }
};
