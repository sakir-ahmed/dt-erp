<?php

namespace App\Console\Commands;

use App\Actions\GetMessengerSenderProfile;
use App\Actions\WhatsAppContactNames;
use App\Actions\WhatsAppRecipient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

class BackfillConverseContactProfiles extends Command
{
    protected $signature = 'converse:backfill-contact-profiles';

    protected $description = 'Restore available contact names and warm the Messenger profile cache without replaying messages';

    public function handle(WhatsAppContactNames $names, GetMessengerSenderProfile $messenger): int
    {
        $restored = 0;
        DB::table('cv_webhook_events')->where('provider', 'whatsapp')->whereNotNull('processed_at')
            ->select('id', 'payload')->chunkById(100, function ($events) use ($names, &$restored): void {
                foreach ($events as $event) {
                    try {
                        $payload = json_decode(Crypt::decryptString($event->payload), true, flags: JSON_THROW_ON_ERROR);
                    } catch (DecryptException|JsonException) {
                        continue;
                    }
                    foreach ((array) data_get($payload, 'entry', []) as $entry) {
                        foreach ((array) data_get($entry, 'changes', []) as $change) {
                            if (data_get($change, 'field') === 'messages') {
                                $restored += $this->restoreWhatsAppNames(data_get($change, 'value'), $names);
                            }
                        }
                    }
                }
            });

        $available = 0;
        DB::table('cv_conversations as thread')
            ->join('cv_contact_identities as identity', 'identity.id', '=', 'thread.contact_identity_id')
            ->join('cv_channel_accounts as account', 'account.id', '=', 'identity.channel_account_id')
            ->where('account.type', 'messenger')->where('account.is_demo', false)->where('account.enabled', true)
            ->whereNotNull('account.send_token')->select('thread.id')
            ->chunkById(100, function ($threads) use ($messenger, &$available): void {
                foreach ($threads as $thread) {
                    if ($messenger((int) $thread->id) !== []) {
                        $available++;
                    }
                }
            }, 'thread.id', 'id');

        $this->info("WhatsApp names restored: {$restored}; Messenger profiles available: {$available}.");

        return self::SUCCESS;
    }

    private function restoreWhatsAppNames(mixed $event, WhatsAppContactNames $names): int
    {
        $senderNames = $names($event);
        if ($senderNames === []) {
            return 0;
        }
        $accountId = data_get($event, 'metadata.phone_number_id');
        if (! is_string($accountId)) {
            return 0;
        }

        $restored = 0;
        foreach ((array) data_get($event, 'messages', []) as $message) {
            $id = data_get($message, 'id');
            $phone = WhatsAppRecipient::phone(data_get($message, 'from'));
            $businessId = WhatsAppRecipient::businessId(data_get($message, 'from_user_id'));
            $name = $senderNames[$phone ?? ''] ?? $senderNames[$businessId ?? ''] ?? null;
            if (! is_string($id) || $name === null || data_get($message, 'group_id') !== null) {
                continue;
            }

            $contactId = DB::table('cv_messages as message')
                ->join('cv_conversations as thread', 'thread.id', '=', 'message.conversation_id')
                ->join('cv_contact_identities as identity', 'identity.id', '=', 'thread.contact_identity_id')
                ->join('cv_channel_accounts as account', 'account.id', '=', 'identity.channel_account_id')
                ->where('account.type', 'whatsapp')->where('account.is_demo', false)->where('account.external_id', $accountId)
                ->where('message.direction', 'in')->where('message.external_id', $id)
                ->where(fn ($query) => $query->whereIn('identity.external_id', array_filter([$phone, $businessId]))
                    ->orWhereIn('identity.whatsapp_user_id', array_filter([$phone, $businessId])))
                ->value('identity.contact_id');
            if ($contactId !== null) {
                $restored += DB::table('cv_contacts')->where('id', $contactId)->whereNull('name')
                    ->update(['name' => $name, 'updated_at' => now()]);
            }
        }

        return $restored;
    }
}
