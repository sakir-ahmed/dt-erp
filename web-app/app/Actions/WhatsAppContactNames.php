<?php

namespace App\Actions;

class WhatsAppContactNames
{
    /** @return array<string, string> */
    public function __invoke(mixed $value): array
    {
        $names = [];
        foreach ((array) data_get($value, 'contacts', []) as $contact) {
            $name = data_get($contact, 'profile.name');
            if (! is_string($name) || ! mb_check_encoding($name, 'UTF-8') || trim($name) === '') {
                continue;
            }
            foreach (array_filter([WhatsAppRecipient::phone(data_get($contact, 'wa_id')),
                WhatsAppRecipient::businessId(data_get($contact, 'user_id'))]) as $address) {
                $names[$address] = mb_substr(trim($name), 0, 255);
            }
        }

        return $names;
    }
}
