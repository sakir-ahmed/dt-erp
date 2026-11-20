<?php

namespace App\Jobs;

use App\Actions\GetMessengerSenderProfile;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshConverseMessengerProfile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct(public int $conversationId) {}

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(GetMessengerSenderProfile $profile): void
    {
        $profile($this->conversationId);
    }
}
