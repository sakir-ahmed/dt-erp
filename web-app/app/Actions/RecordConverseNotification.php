<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class RecordConverseNotification
{
    public function inboundWhileAi(int $conversation): void
    {
        $this->record($conversation, 'ai_inbound');
    }

    public function aiReplyAccepted(int $conversation): void
    {
        $this->record($conversation, 'ai_reply');
    }

    public function humanHandoff(int $conversation, bool $manual = false): void
    {
        $this->record($conversation, $manual ? 'manual_handoff' : 'human_handoff');
    }

    private function record(int $conversation, string $type): void
    {
        // Serialize inserts until the enclosing transaction commits so the poll cursor cannot skip a late commit.
        DB::table('cv_notification_stream')->where('id', 1)->lockForUpdate()->first();
        DB::table('cv_notification_events')->insert([
            'conversation_id' => $conversation, 'type' => $type, 'created_at' => now(),
        ]);
    }
}
