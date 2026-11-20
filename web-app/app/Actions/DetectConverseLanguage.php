<?php

namespace App\Actions;

class DetectConverseLanguage
{
    private const string BANGLISH = '/\b(koto+|ache|ase|lagbe|kemon|dam|daam|bhai|apni|ami|ki|kivabe|kibhabe|bolen|den|chai|er|ta|shathe|salam|kaj|kore|hoy|jonno|diye|korte|bujhte)\b/i';

    public function message(string $text): string
    {
        if (preg_match('/\benglish\s*(?:e|te)?\s*(?:bolen|bolun|please)\b|ইংরেজিতে/ui', $text)) {
            return 'en';
        }
        if (preg_match('/\bbanglish\b/ui', $text)) {
            return 'banglish';
        }
        if (preg_match_all('/\p{Bengali}/u', $text) >= max(1, preg_match_all('/[a-z]/i', $text) / 2)) {
            return 'bn';
        }

        return preg_match(self::BANGLISH, $text) ? 'banglish' : 'en';
    }

    public function document(string $text): string
    {
        $bengali = preg_match_all('/\p{Bengali}/u', $text);
        $latinWords = preg_match_all('/\b[a-z]{2,}\b/i', $text);
        $banglishWords = preg_match_all(self::BANGLISH, $text);
        if ($bengali > 0) {
            return $latinWords >= 2 ? 'mixed' : 'bn';
        }
        if ($latinWords === 0) {
            return 'unknown';
        }
        if ($banglishWords === 0) {
            return 'en';
        }

        return $latinWords >= 8 && $banglishWords / $latinWords < 0.2 ? 'mixed' : 'banglish';
    }

    /** @param list<string> $languages */
    public function overall(array $languages): string
    {
        $distinct = array_values(array_unique(array_diff($languages, ['unknown'])));

        return count($distinct) > 1 ? 'mixed' : ($distinct[0] ?? 'unknown');
    }
}
