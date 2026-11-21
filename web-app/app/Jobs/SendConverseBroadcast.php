<?php

namespace App\Jobs;

use App\Actions\SendConverseReply;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendConverseBroadcast implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public int $userId,
        public int $conversation,
        public string $operationId,
        public string $body,
    ) {}

    public function handle(SendConverseReply $send): void
    {
        $user = User::query()->where('is_demo', false)->find($this->userId);
        if ($user === null) {
            return;
        }

        $status = $send->broadcast($user, $this->conversation, $this->operationId, $this->body);
        if ($status !== 'accepted') {
            Log::channel('converse')->info('broadcast delivery not accepted', [
                'conversation_id' => $this->conversation, 'status' => $status,
            ]);
        }
    }
}
