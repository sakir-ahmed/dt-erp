<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class MatchConverseCorrection
{
    /**
     * Find a human-approved correction whose question is contained in the
     * customer's message, scoped to the eligible collections or global ones.
     */
    public function __invoke(string $message, array $collectionIds): ?object
    {
        $normalized = $this->normalize($message);
        if (mb_strlen($normalized) < 4) {
            return null;
        }

        $correction = DB::table('cv_knowledge_corrections')->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('collection_id')->orWhereIn('collection_id', $collectionIds))
            ->orderBy('collection_id') // Collection-specific corrections beat global ones.
            ->orderByDesc('id')
            ->get(['id', 'collection_id', 'question', 'answer'])
            ->first(fn (object $row): bool => $normalized !== ''
                && str_contains($normalized, $this->normalize($row->question)));

        return $correction === null ? null : (object) [...(array) $correction, 'correction' => true];
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[\s\p{P}]+/u', ' ', mb_strtolower($value)));
    }
}
