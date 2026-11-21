<?php

namespace App\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResolveConverseKnowledgeScope
{
    /**
     * @return array{type: string, collection_ids: list<int>, connected_collection_ids: list<int>, subject_id: ?int, label: ?string, restrict_subject: bool}
     */
    public function __invoke(Collection $history, array $allowedCollections, bool $demo, array $connected = [], bool $manual = false): array
    {
        $collections = $this->availableCollections($allowedCollections, $demo);
        $collectionIds = $collections->keys()->all();
        $connected = array_values(array_intersect(array_map(intval(...), $connected), $collectionIds));
        $today = now('Asia/Dhaka')->toDateString();
        $subjects = DB::table('cv_knowledge_entries')->whereIn('collection_id', $collectionIds)->whereNull('document_id')
            ->whereIn('kind', ['product', 'service'])->where('enabled', true)->where('public', true)
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->get(['id', 'collection_id', 'kind', 'title']);

        $active = null;
        foreach ($history->where('direction', 'in')->reverse()->values() as $index => $turn) {
            $message = ' '.$this->normalize($turn->body).' ';
            $namedSubjects = $subjects->filter(fn (object $subject): bool => $this->mentions($message, $subject->title));
            $namedCollections = $collections->filter(function (object $collection) use ($message): bool {
                $keywords = json_decode($collection->keywords ?? '[]', true);

                return $this->mentions($message, $collection->name)
                    || (is_array($keywords) && array_any($keywords,
                        fn (mixed $keyword): bool => is_string($keyword) && $this->mentions($message, $keyword)));
            })->keys()->all();
            $matched = array_values(array_unique([...$namedCollections, ...$namedSubjects->pluck('collection_id')->all()]));
            if ($matched === []) {
                continue;
            }

            if ($manual) {
                $matched = array_values(array_intersect($matched, $connected));
                if ($matched === []) {
                    if ($index === 0) {
                        $active = $this->collections([], $collections);
                        break;
                    }

                    continue;
                }
                $namedSubjects = $namedSubjects->whereIn('collection_id', $matched);
            } else {
                $connected = array_values(array_unique([...$connected, ...$matched]));
            }
            if ($active !== null) {
                continue;
            }

            if (count($matched) === 1 && $namedSubjects->count() === 1) {
                $subject = $namedSubjects->first();
                $active = ['type' => $subject->kind, 'collection_ids' => $matched, 'subject_id' => $subject->id,
                    'label' => $subject->title, 'restrict_subject' => $subjects->where('collection_id', $subject->collection_id)->count() > 1];
            } else {
                $active = $this->collections($matched, $collections);
            }
        }

        if (! $manual && $connected === [] && count($collectionIds) === 1) {
            $connected = $collectionIds;
        }
        $fallback = $manual || $connected !== [] ? $connected : $collectionIds;

        return [...($active ?? $this->collections($fallback, $collections)), 'connected_collection_ids' => $connected];
    }

    public function availableCollections(array $allowedCollections, bool $demo): Collection
    {
        return DB::table('cv_knowledge_collections')->whereIn('id', $allowedCollections)
            ->where('enabled', true)->where('is_demo', $demo)->orderBy('name')->get(['id', 'name', 'keywords'])->keyBy('id');
    }

    private function collections(array $ids, Collection $collections): array
    {
        if ($ids === []) {
            return ['type' => 'none', 'collection_ids' => [], 'subject_id' => null, 'label' => null, 'restrict_subject' => false];
        }
        $all = count($ids) > 1 && count($ids) === $collections->count();

        return ['type' => count($ids) === 1 ? 'collection' : ($all ? 'all' : 'collections'), 'collection_ids' => $ids,
            'subject_id' => null, 'label' => $all ? null : $collections->only($ids)->pluck('name')->implode(', '), 'restrict_subject' => false];
    }

    private function mentions(string $message, string $phrase): bool
    {
        $phrase = $this->normalize($phrase);

        return mb_strlen($phrase) >= 2 && str_contains($message, ' '.$phrase.' ');
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', mb_strtolower($text)));
    }
}
