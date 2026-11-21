<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class MatchConverseCorrection
{
    /**
     * Match a whole approved phrase. Prefer a collection answer and then
     * the most specific phrase within that scope.
     */
    public function __invoke(string $message, array $collectionIds): ?object
    {
        $normalized = $this->normalize($message);
        if (mb_strlen($normalized) < 3) {
            return null;
        }

        $message = ' '.$normalized.' ';
        $match = DB::table('cv_knowledge_corrections')->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('collection_id')->orWhereIn('collection_id', $collectionIds))
            ->get(['id', 'collection_id', 'question', 'answer', 'follow_up', 'catalog_attachment_id'])
            ->map(fn (object $row): array => ['correction' => $row, 'length' => $this->matchingLength($message, $row->question)])
            ->filter(fn (array $match): bool => $match['length'] > 0)
            ->sortByDesc(fn (array $match): array => [
                $match['correction']->collection_id !== null,
                $match['length'],
                $match['correction']->id,
            ])->first();

        return $match === null ? null : (object) [...(array) $match['correction'], 'correction' => true];
    }

    private function matchingLength(string $message, string $question): int
    {
        $longest = 0;
        foreach (explode('|', $question) as $variant) {
            $phrase = $this->normalize($variant);
            $length = mb_strlen($phrase);
            if ($length >= 3 && str_contains($message, ' '.$phrase.' ')) {
                $longest = max($longest, $length);
            }
        }

        return $longest;
    }

    private function normalize(string $value): string
    {
        $value = trim((string) preg_replace('/[\s\p{P}]+/u', ' ', mb_strtolower($value)));

        return trim(str_replace(' e mail ', ' email ', ' '.$value.' '));
    }
}
