<?php

namespace App\Jobs;

use App\Actions\RecordConverseInboundMessage;
use App\Actions\RecordWhatsAppDeliveryStatus;
use App\Actions\SaveConverseInboundAttachment;
use App\Actions\WhatsAppContactNames;
use App\Actions\WhatsAppRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ProcessConverseWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $eventId) {}

    public function handle(RecordConverseInboundMessage $record, RecordWhatsAppDeliveryStatus $delivery, WhatsAppContactNames $contactNames, SaveConverseInboundAttachment $attachments): void
    {
        $event = DB::table('cv_webhook_events')->where('id', $this->eventId)->firstOrFail();
        if ($event->processed_at !== null) {
            return;
        }

        $payload = json_decode(Crypt::decryptString($event->payload), true, flags: JSON_THROW_ON_ERROR);
        foreach ($payload['entry'] as $entry) {
            if ($event->provider === 'meta') {
                $this->recordMessengerMessages($entry, $record, $attachments);
            } else {
                $this->recordWhatsAppMessages($entry, $record, $attachments, $delivery, $contactNames);
            }
        }

        DB::table('cv_webhook_events')->where('id', $this->eventId)->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }

    private function recordMessengerMessages(mixed $entry, RecordConverseInboundMessage $record, SaveConverseInboundAttachment $attachments): void
    {
        $account = $this->accountId('messenger', data_get($entry, 'id'));
        if ($account === null) {
            return;
        }

        foreach ((array) data_get($entry, 'messaging', []) as $message) {
            if (data_get($message, 'message.is_echo', false)) {
                continue;
            }

            $this->recordText($record, $attachments, $account, 'messenger', data_get($message, 'sender.id'),
                data_get($message, 'message.mid'), data_get($message, 'message.text'),
                data_get($message, 'timestamp'), milliseconds: true, attachment: $this->messengerAttachment($message));
        }
    }

    private function recordWhatsAppMessages(mixed $entry, RecordConverseInboundMessage $record, SaveConverseInboundAttachment $attachments, RecordWhatsAppDeliveryStatus $delivery, WhatsAppContactNames $contactNames): void
    {
        foreach ((array) data_get($entry, 'changes', []) as $change) {
            if (data_get($change, 'field') !== 'messages') {
                continue;
            }

            $account = $this->accountId('whatsapp', data_get($change, 'value.metadata.phone_number_id'));
            if ($account === null) {
                continue;
            }

            foreach ((array) data_get($change, 'value.statuses', []) as $receipt) {
                $delivery($account, $receipt);
            }

            $names = $contactNames(data_get($change, 'value'));

            foreach ((array) data_get($change, 'value.messages', []) as $message) {
                $type = data_get($message, 'type');
                $body = data_get($message, 'text.body');
                $attachment = null;
                if (($type === 'image' || $type === 'document') && data_get($message, 'group_id') === null) {
                    $body = data_get($message, "{$type}.caption");
                    $media = data_get($message, "{$type}.id");
                    if (is_string($media) && $media !== '' && strlen($media) <= 255) {
                        $attachment = ['media' => $media,
                            'name' => is_string(data_get($message, "{$type}.filename")) ? data_get($message, "{$type}.filename") : null,
                            'sha256' => data_get($message, "{$type}.sha256")];
                    }
                } elseif ($type !== 'text' || data_get($message, 'group_id') !== null) {
                    continue;
                }

                $phone = WhatsAppRecipient::phone(data_get($message, 'from'));
                $businessId = WhatsAppRecipient::businessId(data_get($message, 'from_user_id'));
                $sender = $phone ?? $businessId;
                if ($sender !== null) {
                    $this->recordText($record, $attachments, $account, 'whatsapp', $sender, data_get($message, 'id'),
                        $body, data_get($message, 'timestamp'), name: $names[$sender] ?? $names[$businessId ?? ''] ?? null,
                        whatsappUserId: $businessId, attachment: $attachment);
                }
            }
        }
    }

    /**
     * A single stored attachment per inbound message: the first image or file
     * with a usable payload URL; other kinds are ignored.
     */
    private function messengerAttachment(array $message): ?array
    {
        foreach ((array) data_get($message, 'message.attachments', []) as $attachment) {
            if (in_array(data_get($attachment, 'type'), ['image', 'file'], true)) {
                $url = data_get($attachment, 'payload.url');
                if (is_string($url) && $url !== '' && strlen($url) <= 2048) {
                    return ['url' => $url];
                }
            }
        }

        return null;
    }

    private function accountId(string $type, mixed $externalId): ?int
    {
        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        $accountId = DB::table('cv_channel_accounts')->where('type', $type)
            ->where('external_id', $externalId)->where('is_demo', false)->where('enabled', true)->value('id');

        return $accountId === null ? null : (int) $accountId;
    }

    private function recordText(RecordConverseInboundMessage $record, SaveConverseInboundAttachment $attachments, int $account, string $channel, mixed $sender, mixed $message, mixed $body, mixed $timestamp, ?string $name = null, bool $milliseconds = false, ?string $whatsappUserId = null, ?array $attachment = null): void
    {
        $hasBody = is_string($body) && trim($body) !== '' && strlen($body) <= 60_000;
        if (! is_string($sender) || $sender === '' || mb_strlen($sender) > 255
            || ! is_string($message) || $message === '' || mb_strlen($message) > 255
            || (! $hasBody && $attachment === null)
            || (! is_int($timestamp) && ! is_string($timestamp))
            || ! ctype_digit((string) $timestamp) || strlen((string) $timestamp) > ($milliseconds ? 13 : 10)) {
            return;
        }

        $seconds = $milliseconds ? intdiv((int) $timestamp, 1000) : (int) $timestamp;
        $id = $record($account, $sender, $message, $hasBody ? $body : '', Carbon::createFromTimestampUTC($seconds), $name, $whatsappUserId);
        if ($id === null) {
            return;
        }
        if ($attachment !== null) {
            $attachments($id, $account, $channel, $attachment['url'] ?? $attachment['media'],
                $attachment['name'] ?? null, $attachment['sha256'] ?? null);
        }
        if ($milliseconds) {
            $conversation = (int) DB::table('cv_messages')->where('id', $id)->value('conversation_id');
            RefreshConverseMessengerProfile::dispatch($conversation)->onQueue('converse-inbound');
        }
        if ($hasBody && config('converse.ai.auto_reply') === true) {
            ReplyToConverseTurn::dispatch($id)->onQueue('converse-ai')->delay(now()->addSeconds(3));
        }
    }
}
