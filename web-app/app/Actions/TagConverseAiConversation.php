<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class TagConverseAiConversation
{
    private const array PATTERNS = [
        'Pricing' => '/\b(price|pricing|fee|cost|koto|dam|subscription)\b|দাম|মূল্য|খরচ|ফি|কত/ui',
        'Demo' => '/\b(demo|demonstration)\b|ডেমো|প্রদর্শনী/ui',
        'Product Inquiry' => '/\b(details|features|brochure|catalog(?:ue)?)\b|বিস্তারিত|ফিচার|ক্যাটালগ|ব্রোশিওর/ui',
        'Attachment Requested' => '/\b(pdf|brochure|catalog(?:ue)?|product book|attach(?:ment)?)\b|পিডিএফ|ব্রোশিওর|ক্যাটালগ/ui',
        'Support' => '/\b(support|error|broken|problem|refund|complaint)\b|সমস্যা|অভিযোগ|সহায়তা/ui',
    ];

    public function __invoke(int $conversation, string $message, array $scope): void
    {
        $names = array_keys(array_filter(self::PATTERNS, fn (string $pattern): bool => preg_match($pattern, $message) === 1));
        if (in_array($scope['type'], ['product', 'service'], true) && $scope['label'] !== null) {
            $names[] = mb_substr($scope['label'], 0, 60);
        }
        if ($names === []) {
            return;
        }

        $pivots = DB::table('cv_conversation_tag')->where('conversation_id', $conversation);
        $existing = $pivots->join('cv_conversation_tags', 'cv_conversation_tags.id', '=', 'cv_conversation_tag.tag_id')
            ->pluck('cv_conversation_tags.name')->all();
        $names = array_slice(array_values(array_diff(array_unique($names), $existing)), 0, max(0, 20 - count($existing)));
        foreach ($names as $name) {
            DB::table('cv_conversation_tags')->insertOrIgnore([
                'name' => $name, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = DB::table('cv_conversation_tags')->where('name', $name)->soleValue('id');
            DB::table('cv_conversation_tag')->insertOrIgnore(['conversation_id' => $conversation, 'tag_id' => $id]);
        }
    }
}
