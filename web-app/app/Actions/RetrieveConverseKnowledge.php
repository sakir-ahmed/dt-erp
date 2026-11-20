<?php

namespace App\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RetrieveConverseKnowledge
{
    public function __construct(private ConverseAiSettings $configuration, private EmbedConverseText $embed) {}

    public function __invoke(array $collectionIds, string $search, bool $demo = false, int $limit = 10, ?string $semanticQuery = null, ?int $entryId = null): Collection
    {
        $today = now('Asia/Dhaka')->toDateString();
        $term = trim(mb_substr($search, 0, 100));

        $model = $semanticQuery !== null ? $this->configuration->get()['embedding_model'] : null;
        $vector = is_string($model) && trim($semanticQuery ?? '') !== ''
            ? (($this->embed)([mb_substr($semanticQuery, 0, 1200)], $model)[0] ?? null) : null;

        $candidates = DB::table('cv_knowledge_entries as entry')
            ->join('cv_knowledge_collections as collection', 'collection.id', '=', 'entry.collection_id')
            ->leftJoin('cv_knowledge_entries as subject', 'subject.id', '=', 'entry.subject_id')
            ->leftJoin('cv_knowledge_documents as document', 'document.id', '=', 'entry.document_id')
            ->where(fn (Builder $query) => $query->whereNull('entry.document_id')->orWhere(fn (Builder $document) => $this->published($document, 'document', $today)->where('document.status', 'indexed')
                ->whereColumn('document.collection_id', 'entry.collection_id')))
            ->when($entryId !== null, fn (Builder $query) => $query->where('entry.id', $entryId))
            ->whereIn('collection.id', $collectionIds)->where('collection.is_demo', $demo)
            ->where('collection.enabled', true)
            ->where(fn (Builder $query) => $this->published($query, 'entry', $today))
            ->where(fn (Builder $query) => $query->whereNull('entry.subject_id')
                ->orWhere(fn (Builder $query) => $this->published($query, 'subject', $today)
                    ->whereColumn('subject.collection_id', 'entry.collection_id')))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('entry.title', "%{$term}%")->orWhereLike('entry.body', "%{$term}%")->orWhereLike('subject.title', "%{$term}%")))
            ->orderByDesc('entry.updated_at')->orderByDesc('entry.id')->limit($vector !== null ? 150 : max(1, min(50, $limit)))
            ->get(['entry.id', 'entry.collection_id', 'entry.kind', 'entry.language', 'entry.title', 'entry.body',
                'entry.subject_id', 'subject.title as subject_title', 'entry.amount_type', 'entry.amount', 'entry.currency',
                'entry.valid_from', 'entry.valid_to', 'entry.document_id', 'entry.embedding_model',
                ...($vector !== null ? ['entry.embedding'] : [])]);

        if ($vector !== null) {
            $candidates = $candidates->sortByDesc(function (object $entry) use ($model, $vector, $semanticQuery): float {
                if ($entry->embedding_model === $model && $entry->embedding !== null) {
                    return $this->embed->similarity($vector, json_decode($entry->embedding, true, flags: JSON_THROW_ON_ERROR));
                }

                return mb_stripos($semanticQuery, $entry->subject_title ?? $entry->title) !== false ? 1.0 : 0.0;
            });
        }

        return $candidates->take($limit)->values()->each(function (object $entry): void {
            unset($entry->embedding);
        });
    }

    private function published(Builder $query, string $alias, string $today): Builder
    {
        return $query->where("{$alias}.enabled", true)->where("{$alias}.public", true)
            ->where(fn (Builder $query) => $query->whereNull("{$alias}.valid_from")->orWhere("{$alias}.valid_from", '<=', $today))
            ->where(fn (Builder $query) => $query->whereNull("{$alias}.valid_to")->orWhere("{$alias}.valid_to", '>=', $today));
    }
}
