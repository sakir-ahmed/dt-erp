<?php

namespace App\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordWhatsAppDeliveryStatus
{
    public const array ACCEPTED = ['accepted', 'sent', 'delivered', 'read'];

    public function __invoke(int $account, mixed $receipt): void
    {
        $messageId = data_get($receipt, 'id');
        $status = data_get($receipt, 'status');
        $operationId = data_get($receipt, 'biz_opaque_callback_data');
        $recipient = WhatsAppRecipient::phone(data_get($receipt, 'recipient_id'));
        $businessId = WhatsAppRecipient::businessId(data_get($receipt, 'recipient_user_id'));

        if (! is_string($messageId) || ! str_starts_with($messageId, 'wamid.') || strlen($messageId) > 255
            || ! in_array($status, ['sent', 'delivered', 'read', 'failed'], true)
            || data_get($receipt, 'recipient_type') === 'group' || ($recipient === null && $businessId === null)) {
            return;
        }

        DB::transaction(function () use ($account, $messageId, $status, $operationId, $recipient, $businessId): void {
            $query = DB::table('cv_messages as message')
                ->join('cv_conversations as thread', 'thread.id', '=', 'message.conversation_id')
                ->join('cv_contact_identities as identity', 'identity.id', '=', 'thread.contact_identity_id')
                ->where('identity.channel_account_id', $account)->where('message.direction', 'out')
                ->where(function (Builder $query) use ($recipient, $businessId): void {
                    $addresses = array_filter([$recipient, $businessId]);
                    $query->whereIn('identity.external_id', $addresses)
                        ->orWhereIn('identity.whatsapp_reply_address', $addresses)->orWhereIn('identity.whatsapp_user_id', $addresses);
                })
                ->where(function (Builder $query) use ($messageId, $operationId): void {
                    $query->where('message.external_id', $messageId)->orWhere('message.attachment_external_id', $messageId);
                    if (is_string($operationId) && Str::isUuid($operationId)) {
                        $query->orWhere(fn (Builder $pending) => $pending->whereNull('message.external_id')
                            ->where('message.operation_id', $operationId));
                    }
                });

            $message = $query->lockForUpdate()->first(['message.id', 'message.delivery_status', 'message.external_id',
                'message.outbound_asset_id', 'message.attachment_status', 'message.attachment_external_id']);
            if ($message === null) {
                return;
            }

            if ($message->attachment_external_id === $messageId) {
                if ($this->advances($message->attachment_status, $status)) {
                    DB::table('cv_messages')->where('id', $message->id)->update([
                        'delivery_status' => $status === 'failed' ? 'partial' : $status, 'attachment_status' => $status,
                    ]);
                }

                return;
            }
            if ($message->attachment_external_id !== null || $message->delivery_status === 'partial'
                || ! $this->advances($message->delivery_status, $status)) {
                return;
            }

            DB::table('cv_messages')->where('id', $message->id)->update([
                'delivery_status' => $status, 'external_id' => $messageId,
                ...($message->outbound_asset_id !== null ? ['attachment_status' => $status] : []),
            ]);
        }, attempts: 3);
    }

    private function advances(?string $current, string $next): bool
    {
        return match ($next) {
            'sent' => in_array($current, ['sending', 'unknown', 'accepted'], true),
            'delivered' => $current !== 'delivered' && $current !== 'read',
            'read' => $current !== 'read',
            'failed' => in_array($current, ['sending', 'unknown', 'accepted', 'sent'], true),
        };
    }
}
