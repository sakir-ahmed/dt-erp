<?php

namespace App\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RememberConverseConversation
{
    public function __construct(private DetectConverseLanguage $languages) {}

    public function record(int $conversation, int $contact, string $body, CarbonInterface $occurredAt): void
    {
        $text = mb_substr(trim(preg_replace('/\s+/u', ' ', $body) ?? ''), 0, 320);
        $preference = $this->languages->requested($text);
        if ($preference !== null) {
            DB::table('cv_contacts')->where('id', $contact)
                ->where(fn ($query) => $query->whereNull('language_preference_at')->orWhere('language_preference_at', '<=', $occurredAt))
                ->update(['language_preference' => $preference, 'language_preference_at' => $occurredAt]);
        }

        $row = DB::table('cv_conversations')->where('id', $conversation)->lockForUpdate()->first(['memory']);
        $memory = json_decode($row->memory ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $category = match (true) {
            (bool) preg_match('/\b(budget|price|cost|koto|dam)\b|বাজেট|মূল্য|দাম/ui', $text) => 'budget',
            (bool) preg_match('/\b(complaint|problem|error|refund|broken)\b|অভিযোগ|সমস্যা/ui', $text) => 'objection',
            (bool) preg_match('/\b(demo|meeting|timeline|delivery)\b|ডেমো|সময়/ui', $text) => 'next_step',
            (bool) preg_match('/\b(need|want|looking for|software|product|service|lagbe|chai|erp)\b|প্রয়োজন|সফটওয়্যার/ui', $text) => 'needs',
            default => null,
        };

        if ($category !== null && ($memory[$category]['at'] ?? 0) <= $occurredAt->getTimestamp()) {
            $memory[$category] = ['text' => $text, 'at' => $occurredAt->getTimestamp()];
            DB::table('cv_conversations')->where('id', $conversation)
                ->update(['memory' => json_encode($memory, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)]);
        }
    }
}
