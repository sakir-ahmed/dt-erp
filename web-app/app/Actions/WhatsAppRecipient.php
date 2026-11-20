<?php

namespace App\Actions;

class WhatsAppRecipient
{
    public static function phone(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\+?([1-9][0-9]{5,14})$/D', $value, $matches) === 1 ? $matches[1] : null;
    }

    public static function businessId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z]{2}\.(?:ENT\.)?[A-Za-z0-9_-]{1,100}$/D', $value) === 1 ? $value : null;
    }
}
