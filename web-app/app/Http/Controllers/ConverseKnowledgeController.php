<?php

namespace App\Http\Controllers;

use App\Actions\RecordConverseAudit;
use App\Jobs\IndexConverseDocument;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConverseKnowledgeController extends Controller
{
    private const string DOCUMENT_EXTENSIONS = 'extensions:md,txt,pdf,docx,csv,xlsx,json';

    private const array KINDS = ['product', 'service', 'company', 'faq', 'price', 'offer'];

    public function index(Request $request): Response
    {
        $collections = $this->collections($request)->orderBy('name')->orderBy('id')
            ->simplePaginate(25, ['id', 'name', 'enabled'], 'collections_page')->withQueryString();
        $selected = $request->integer('collection', (int) ($collections->items()[0]->id ?? 0));
        $collection = $selected > 0 ? $this->collections($request)->where('id', $selected)->first() : null;
        abort_if($selected > 0 && $collection === null, 404);

        return Inertia::render('Converse/Knowledge', [
            'collections' => $collections,
            'collection' => $collection,
            'entries' => $collection ? DB::table('cv_knowledge_entries')->where('collection_id', $collection->id)
                ->whereNull('document_id')->orderByDesc('id')->simplePaginate(25, ['*'], 'entries_page')->withQueryString() : null,
            'outboundAssets' => $collection && ! $request->user()->is_demo
                ? DB::table('cv_outbound_assets')->where('collection_id', $collection->id)->orderBy('title')->get(['id', 'title', 'name', 'approved']) : [],
            'documents' => $collection ? DB::table('cv_knowledge_documents')->where('collection_id', $collection->id)
                ->orderByDesc('priority')->orderByDesc('id')
                ->get(['id', 'name', 'format', 'language', 'enabled', 'public', 'status', 'error', 'valid_from', 'valid_to', 'priority']) : [],
            'subjects' => $collection ? DB::table('cv_knowledge_entries')->where('collection_id', $collection->id)
                ->whereIn('kind', ['product', 'service'])->orderBy('title')->get(['id', 'title']) : [],
            'kinds' => self::KINDS,
        ]);
    }

    public function saveCollection(Request $request, RecordConverseAudit $audit, ?int $collection = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'enabled' => ['required', 'boolean']]);
        $id = DB::transaction(function () use ($request, $audit, $collection, $data): int {
            $values = [...$data, 'updated_at' => now()];
            if ($collection === null) {
                $id = DB::table('cv_knowledge_collections')->insertGetId([
                    ...$values, 'is_demo' => (bool) $request->user()->is_demo, 'created_at' => now(),
                ]);
            } else {
                $scope = $this->collections($request)->where('id', $collection);
                abort_if($scope->lockForUpdate()->first() === null, 404);
                $scope->update($values);
                $id = $collection;
            }
            $audit($request->user()->id, 'knowledge.collection_saved', 'knowledge_collection', (int) $id, $data);

            return (int) $id;
        });

        Inertia::flash('success', __('workspace.knowledge_saved'));

        return to_route('converse.knowledge', ['collection' => $id]);
    }

    public function saveEntry(Request $request, RecordConverseAudit $audit, int $collection, ?int $entry = null): RedirectResponse
    {
        abort_unless($this->collections($request)->where('id', $collection)->exists(), 404);
        $kind = $request->input('kind');
        $commercial = in_array($kind, ['price', 'offer'], true);
        $amountTypes = $kind === 'price' ? ['fixed', 'from', 'monthly', 'yearly', 'one_time', 'quote'] : ['fixed', 'percent'];
        $amountType = $request->input('amount_type');
        $hasAmount = $commercial && $amountType !== 'quote';
        $hasCurrency = $hasAmount && $amountType !== 'percent';
        $data = $request->validate([
            'kind' => ['required', Rule::in(self::KINDS)],
            'language' => ['required', Rule::in(['en', 'bn', 'banglish'])],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:12000'],
            'subject_id' => [Rule::excludeIf(! $commercial), 'required', 'integer',
                Rule::exists('cv_knowledge_entries', 'id')->where(fn (Builder $query) => $query
                    ->where('collection_id', $collection)->whereIn('kind', ['product', 'service']))],
            'amount_type' => [Rule::excludeIf(! $commercial), 'required', Rule::in($amountTypes)],
            'amount' => [Rule::excludeIf(! $hasAmount), 'required', 'numeric', 'decimal:0,2', 'min:0', $amountType === 'percent' ? 'max:100' : 'max:9999999999.99'],
            'currency' => [Rule::excludeIf(! $hasCurrency), 'required', 'regex:/^[A-Z]{3}$/D'],
            'enabled' => ['required', 'boolean'],
            'public' => ['required', 'boolean'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('valid_from'), 'after_or_equal:valid_from')],
        ]);

        DB::transaction(function () use ($request, $audit, $collection, $entry, $data): void {
            $values = array_replace([
                'body' => null, 'subject_id' => null, 'amount_type' => null, 'amount' => null, 'currency' => null,
                'valid_from' => null, 'valid_to' => null,
            ], $data, ['updated_at' => now()]);

            if ($entry === null) {
                $id = DB::table('cv_knowledge_entries')->insertGetId([
                    ...$values, 'collection_id' => $collection, 'created_at' => now(),
                ]);
            } else {
                $scope = DB::table('cv_knowledge_entries')->where('collection_id', $collection)->whereNull('document_id')->where('id', $entry);
                $previous = $scope->lockForUpdate()->first();
                abort_if($previous === null, 404);
                abort_unless($previous->kind === $data['kind'], 422);
                $scope->update($values);
                $id = $entry;
            }
            $audit($request->user()->id, 'knowledge.entry_saved', 'knowledge_entry', (int) $id,
                array_diff_key($data, ['body' => true]));
        });

        Inertia::flash('success', __('workspace.knowledge_saved'));

        return to_route('converse.knowledge', ['collection' => $collection]);
    }

    public function uploadDocument(Request $request, RecordConverseAudit $audit, int $collection): RedirectResponse
    {
        abort_unless($this->collections($request)->where('id', $collection)->exists(), 404);
        $data = $request->validate([
            'document' => ['required', 'file', 'max:2048', self::DOCUMENT_EXTENSIONS],
        ]);
        $file = $data['document'];
        $format = strtolower($file->getClientOriginalExtension());
        $path = Storage::disk('local')->putFile('converse/knowledge', $file);
        abort_unless(is_string($path), 500);

        $id = DB::transaction(function () use ($request, $audit, $collection, $path, $format, $file): int {
            $id = DB::table('cv_knowledge_documents')->insertGetId([
                'collection_id' => $collection, 'owner_id' => $request->user()->id,
                'name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $path, 'format' => $format, 'language' => 'unknown',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $audit($request->user()->id, 'knowledge.document_uploaded', 'knowledge_document', $id, [
                'collection_id' => $collection, 'format' => $format,
            ]);

            return $id;
        });

        IndexConverseDocument::dispatch($id)->onQueue('converse-knowledge');

        return to_route('converse.knowledge', ['collection' => $collection]);
    }

    public function updateDocument(Request $request, RecordConverseAudit $audit, int $collection, int $document): RedirectResponse
    {
        abort_unless($this->collections($request)->where('id', $collection)->exists(), 404);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'public' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'min:0', 'max:255'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'], 'valid_to' => ['nullable', 'date_format:Y-m-d',
                Rule::when($request->filled('valid_from'), 'after_or_equal:valid_from')],
            'document' => ['nullable', 'file', 'max:2048', self::DOCUMENT_EXTENSIONS],
        ]);
        $replacement = $data['document'] ?? null;
        unset($data['document']);

        DB::transaction(function () use ($request, $audit, $collection, $document, $data, $replacement): void {
            $scope = DB::table('cv_knowledge_documents')->where('collection_id', $collection)->where('id', $document);
            $current = $scope->lockForUpdate()->firstOrFail();
            $fileData = [];
            if ($replacement !== null) {
                $path = Storage::disk('local')->putFile('converse/knowledge', $replacement);
                abort_unless(is_string($path), 500);
                DB::table('cv_knowledge_document_revisions')->insert([
                    'document_id' => $document, 'owner_id' => $current->owner_id, 'name' => $current->name,
                    'path' => $current->path, 'format' => $current->format, 'language' => $current->language, 'replaced_at' => now(),
                ]);
                $fileData = [
                    'path' => $path, 'format' => strtolower($replacement->getClientOriginalExtension()),
                    'name' => mb_substr($replacement->getClientOriginalName(), 0, 255), 'language' => 'unknown',
                    'owner_id' => $request->user()->id, 'status' => 'queued', 'error' => null,
                ];
            }
            $scope->update([...$data, ...$fileData, 'updated_at' => now()]);
            $audit($request->user()->id, 'knowledge.document_updated', 'knowledge_document', $document, [
                ...$data, 'file_replaced' => $replacement !== null,
            ]);
        });

        if ($replacement !== null) {
            IndexConverseDocument::dispatch($document)->onQueue('converse-knowledge');
        }

        return to_route('converse.knowledge', ['collection' => $collection]);
    }

    public function showDocument(Request $request, int $collection, int $document): BinaryFileResponse
    {
        abort_unless($this->collections($request)->where('id', $collection)->exists(), 404);
        $record = DB::table('cv_knowledge_documents')->where('collection_id', $collection)->where('id', $document)
            ->first(['path', 'name', 'format']);
        abort_if($record === null || ! Storage::disk('local')->exists($record->path), 404);

        $types = [
            'md' => 'text/markdown', 'txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json',
            'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        return response()->file(Storage::disk('local')->path($record->path), [
            'Content-Type' => $types[$record->format] ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$record->name.'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function reindexDocuments(Request $request, RecordConverseAudit $audit, int $collection, ?int $document = null): RedirectResponse
    {
        abort_unless($this->collections($request)->where('id', $collection)->exists(), 404);
        $scope = DB::table('cv_knowledge_documents')->where('collection_id', $collection)
            ->when($document !== null, fn (Builder $query) => $query->where('id', $document));
        $ids = DB::transaction(function () use ($scope, $audit, $request, $collection, $document) {
            $ids = $scope->lockForUpdate()->pluck('id');
            abort_if($document !== null && $ids->isEmpty(), 404);
            $scope->update(['status' => 'queued', 'error' => null, 'updated_at' => now()]);
            $audit($request->user()->id, 'knowledge.documents_requeued', 'knowledge_collection', $collection, ['document_id' => $document]);

            return $ids;
        });

        foreach ($ids as $id) {
            IndexConverseDocument::dispatch((int) $id)->onQueue('converse-knowledge');
        }

        return to_route('converse.knowledge', ['collection' => $collection]);
    }

    private function collections(Request $request): Builder
    {
        return DB::table('cv_knowledge_collections')->where('is_demo', (bool) $request->user()->is_demo);
    }
}
