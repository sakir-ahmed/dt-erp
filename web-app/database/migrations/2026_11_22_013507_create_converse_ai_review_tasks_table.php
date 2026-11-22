<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cv_conversations', function (Blueprint $table) {
            $table->boolean('resume_ai_on_inbound')->default(false);
        });
        Schema::create('cv_ai_review_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('cv_conversations')->cascadeOnDelete();
            $table->foreignId('inbound_message_id')->unique()->constrained('cv_messages')->cascadeOnDelete();
            $table->foreignId('reply_message_id')->nullable()->constrained('cv_messages')->nullOnDelete();
            $table->foreignId('ai_message_id')->nullable()->constrained('cv_messages')->nullOnDelete();
            $table->foreignId('notification_id')->nullable()->constrained('cv_notification_events')->nullOnDelete();
            $table->string('reason', 16);
            $table->date('failure_date');
            $table->timestamp('created_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['conversation_id', 'failure_date', 'reason'], 'cv_review_conversation_date_reason');
        });

        DB::table('cv_conversations as thread')->where('thread.mode', 'human')->where('thread.status', 'pending')
            ->whereExists(fn (Builder $handoff) => $handoff->from('cv_notification_events as event')
                ->whereColumn('event.conversation_id', 'thread.id')->where('event.type', 'human_handoff')
                ->whereNotExists(fn (Builder $manual) => $manual->from('cv_notification_events as later')
                    ->whereColumn('later.conversation_id', 'thread.id')->where('later.type', 'manual_handoff')
                    ->whereColumn('later.id', '>', 'event.id'))
                ->whereNotExists(fn (Builder $audit) => $audit->from('cv_audit_events as audit')
                    ->whereColumn('audit.subject_id', 'thread.id')->where('audit.subject_type', 'conversation')
                    ->where('audit.action', 'conversation.updated')->whereColumn('audit.created_at', '>=', 'event.created_at')))
            ->update(['resume_ai_on_inbound' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_ai_review_tasks');
        Schema::table('cv_conversations', function (Blueprint $table) {
            $table->dropColumn('resume_ai_on_inbound');
        });
    }
};
