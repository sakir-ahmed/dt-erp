<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class RecordConverseAudit
{
    /** @param array<string, mixed> $changes */
    public function __invoke(int $userId, string $action, string $subject, int $subjectId, array $changes): void
    {
        DB::table('cv_audit_events')->insert([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject,
            'subject_id' => $subjectId,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
