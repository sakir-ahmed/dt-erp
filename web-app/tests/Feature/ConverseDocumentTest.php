<?php

use App\Actions\RetrieveConverseKnowledge;
use App\Jobs\IndexConverseDocument;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\ExecutableFinder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite()->seed(DatabaseSeeder::class);
    config(['inertia.ssr.enabled' => false, 'converse.ai.embedding_model' => 'fixture-embed']);
    Storage::fake('local');
    Queue::fake();
    Http::preventStrayRequests();
});

function converseDocumentFixture(string $format): UploadedFile
{
    if ($format === 'pdf') {
        $stream = 'BT /F1 12 Tf 50 700 Td (Fictional sample board) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];
        $contents = "%PDF-1.4\n";
        $xref = "0000000000 65535 f \n";
        foreach ($objects as $index => $object) {
            $xref .= sprintf("%010d 00000 n \n", strlen($contents));
            $contents .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $offset = strlen($contents);
        $contents .= "xref\n0 6\n{$xref}trailer\n<< /Root 1 0 R /Size 6 >>\nstartxref\n{$offset}\n%%EOF\n";
    } elseif ($format === 'docx' || $format === 'xlsx') {
        $path = tempnam(sys_get_temp_dir(), 'converse-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($format === 'pdf') {
            $stream = 'BT /F1 12 Tf 50 700 Td (Fictional sample board) Tj ET';
            $objects = [
                '<< /Type /Catalog /Pages 2 0 R >>',
                '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
                '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
                '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            ];
            $contents = "%PDF-1.4\n";
            $xref = "0000000000 65535 f \n";
            foreach ($objects as $index => $object) {
                $xref .= sprintf("%010d 00000 n \n", strlen($contents));
                $contents .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
            }
            $offset = strlen($contents);
            $contents .= "xref\n0 6\n{$xref}trailer\n<< /Root 1 0 R /Size 6 >>\nstartxref\n{$offset}\n%%EOF\n";
        } elseif ($format === 'docx') {
            $zip->addFromString('word/document.xml', '<document><p><t>Fictional sample board</t></p></document>');
        } else {
            $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Fictional sample board</t></si></sst>');
            $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><row><c t="s"><v>0</v></c></row></worksheet>');
        }
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);
    } else {
        $contents = match ($format) {
            'json' => '{"topic":"Fictional sample board"}',
            'md' => "# Fictional sample board\nExample notes.",
            'csv' => "topic,value\nFictional sample board,Example",
            default => 'Fictional sample board',
        };
    }

    return UploadedFile::fake()->createWithContent("board.{$format}", $contents);
}

it('indexes supported text and Office files and enforces publication, expiry and collection isolation', function (string $format) {
    if ($format === 'pdf' && (new ExecutableFinder)->find('pdftotext') === null) {
        $this->markTestSkipped('Poppler pdftotext is required to extract PDF fixtures.');
    }
    Http::fake(['http://127.0.0.1:11434/api/embed' => Http::response(['embeddings' => [[1, 0]]])]);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture', 'enabled' => true]);
    $admin = User::factory()->create()->assignRole('administrator');
    $this->actingAs($admin)->post(route('converse.knowledge.documents.store', $collection), [
        'document' => converseDocumentFixture($format),
    ])->assertRedirect();
    $document = DB::table('cv_knowledge_documents')->first();
    Storage::disk('local')->assertExists($document->path);
    Queue::assertPushedOn('converse-knowledge', IndexConverseDocument::class);
    app()->call([new IndexConverseDocument($document->id), 'handle']);
    $retrieve = app(RetrieveConverseKnowledge::class);
    expect($retrieve([$collection], ''))->toBeEmpty();
    $url = route('converse.knowledge.documents.update', [$collection, $document->id]);
    $this->patch($url, ['enabled' => true, 'public' => true, 'priority' => 0])->assertRedirect();

    $results = $retrieve([$collection], '', semanticQuery: 'বোর্ড কিভাবে কাজ করে?');
    expect($results->sole()->body)->toContain('Fictional sample board')
        ->and($results->sole())->not->toHaveProperty('embedding')
        ->and($retrieve([$collection], '', demo: true))->toBeEmpty();
    Http::assertSent(fn (Request $request) => $request['input'] === ['বোর্ড কিভাবে কাজ করে?']);

    $this->get(route('converse.knowledge', ['collection' => $collection]))
        ->assertInertia(fn (Assert $page) => $page->where('documents.0.status', 'indexed')
            ->missing('documents.0.path')->has('entries.data', 0));
    $this->patch($url, ['enabled' => true, 'public' => true, 'priority' => 0, 'valid_to' => now('Asia/Dhaka')->subDay()->toDateString()])->assertRedirect();
    expect($retrieve([$collection], ''))->toBeEmpty();

    $this->post(route('converse.knowledge.documents.reindex', [$collection, $document->id]))->assertRedirect();
    $this->assertDatabaseHas('cv_knowledge_documents', ['id' => $document->id, 'status' => 'queued']);
    app()->call([new IndexConverseDocument($document->id), 'handle']);
    $this->assertDatabaseCount('cv_knowledge_entries', 1);
})->with(['md', 'txt', 'pdf', 'csv', 'json', 'docx', 'xlsx']);

it('reports provider failure and keeps unindexed documents out of retrieval', function () {
    Http::fake(['http://127.0.0.1:11434/api/embed' => Http::response([], 503)]);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture', 'enabled' => true]);
    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->post(route('converse.knowledge.documents.store', $collection), [
            'document' => converseDocumentFixture('md'),
        ])->assertRedirect();

    $document = DB::table('cv_knowledge_documents')->first();
    app()->call([new IndexConverseDocument($document->id), 'handle']);
    $this->assertDatabaseHas('cv_knowledge_documents', ['status' => 'failed', 'error' => 'The local embedding model did not return usable vectors.']);
    $this->assertDatabaseCount('cv_knowledge_entries', 0);
});

it('ranks published document chunks by semantic similarity before applying the result limit', function () {
    Http::fake(['http://127.0.0.1:11434/api/embed' => Http::response(['embeddings' => [[1, 0]]])]);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture', 'enabled' => true]);
    $author = User::factory()->create();
    $document = DB::table('cv_knowledge_documents')->insertGetId([
        'collection_id' => $collection, 'owner_id' => $author->id, 'name' => 'Fixture',
        'path' => 'fixture', 'format' => 'txt', 'language' => 'en', 'enabled' => true, 'public' => true, 'status' => 'indexed',
    ]);
    $common = ['collection_id' => $collection, 'document_id' => $document, 'kind' => 'document', 'language' => 'en',
        'enabled' => true, 'public' => true, 'embedding_model' => 'fixture-embed'];
    DB::table('cv_knowledge_entries')->insert([
        [...$common, 'title' => 'Board', 'embedding' => '[0.98,0.01]'],
        [...$common, 'title' => 'Oranges', 'embedding' => '[0.01,0.98]'],
    ]);
    $result = app(RetrieveConverseKnowledge::class)([$collection], '', limit: 1, semanticQuery: 'নমুনা বোর্ড কোথায়?');
    expect($result->sole()->title)->toBe('Board');
});

it('infers document and chunk language from the source without an upload picker', function (string $body, string $language) {
    Http::fake(['http://127.0.0.1:11434/api/embed' => Http::response(['embeddings' => [[1, 0]]])]);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture']);
    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->post(route('converse.knowledge.documents.store', $collection), [
            'document' => UploadedFile::fake()->createWithContent('language.txt', $body),
        ])->assertRedirect();

    $id = DB::table('cv_knowledge_documents')->soleValue('id');
    app()->call([new IndexConverseDocument($id), 'handle']);
    $this->assertDatabaseHas('cv_knowledge_documents', ['id' => $id, 'language' => $language, 'status' => 'indexed']);
    $this->assertDatabaseHas('cv_knowledge_entries', ['document_id' => $id, 'language' => $language]);
})->with([
    ['This guide explains how to organize the project board.', 'en'],
    ['এই নির্দেশিকা প্রকল্পের কাজ সাজানোর পদ্ধতি ব্যাখ্যা করে।', 'bn'],
    ['Apni ei board diye kaj kivabe sajaben ta bujhte parben.', 'banglish'],
    ['এই নির্দেশিকা explains how to organize the project board.', 'mixed'],
    ['1234 — 5678', 'unknown'],
]);

it('replaces a file without a language field, preserves the old upload and indexes mixed sections for either query language', function () {
    Http::fake(['http://127.0.0.1:11434/api/embed' => function (Request $request) {
        return Http::response(['embeddings' => array_fill(0, count($request['input']), [1, 0])]);
    }]);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture', 'enabled' => true]);
    $this->actingAs(User::factory()->create()->assignRole('administrator'))
        ->post(route('converse.knowledge.documents.store', $collection), [
            'document' => converseDocumentFixture('md'),
        ])->assertRedirect();
    $previous = DB::table('cv_knowledge_documents')->first();
    app()->call([new IndexConverseDocument($previous->id), 'handle']);

    $replacement = "This guide explains how to organize the project board.\n\n"
        ."এই নির্দেশিকা প্রকল্পের কাজ সাজানোর পদ্ধতি ব্যাখ্যা করে।\n\n"
        ."Apni ei board diye kaj kivabe sajaben ta bujhte parben.\n\n"
        ."এই নির্দেশিকা explains how to organize the project board.\n\n1234 — 5678";
    $this->post(route('converse.knowledge.documents.update', [$collection, $previous->id]), [
        '_method' => 'PATCH', 'enabled' => true, 'public' => true, 'priority' => 5,
        'document' => UploadedFile::fake()->createWithContent('updated.md', $replacement),
    ])->assertRedirect();
    Storage::disk('local')->assertExists($previous->path);
    $this->assertDatabaseHas('cv_knowledge_document_revisions', ['document_id' => $previous->id, 'path' => $previous->path, 'language' => 'en']);
    $this->assertDatabaseHas('cv_knowledge_documents', ['id' => $previous->id, 'status' => 'queued', 'language' => 'unknown']);

    $retrieve = app(RetrieveConverseKnowledge::class);
    expect($retrieve([$collection], ''))->toBeEmpty();
    app()->call([new IndexConverseDocument($previous->id), 'handle']);
    $this->assertDatabaseHas('cv_knowledge_documents', ['id' => $previous->id, 'status' => 'indexed', 'language' => 'mixed']);
    foreach (['How does the project board work?', 'প্রজেক্ট বোর্ড কীভাবে কাজ করে?'] as $query) {
        $results = $retrieve([$collection], '', semanticQuery: $query);
        expect($results->pluck('language')->sort()->values()->all())->toBe(['banglish', 'bn', 'en', 'mixed', 'unknown']);
        expect($results->pluck('title')->unique()->all())->toBe(['updated.md']);
    }
});

it('edits document priority, orders by it, ranks retrieval, and previews the file', function () {
    Http::fake(['http://127.0.0.1:11434/api/embed' => function (Request $request) {
        $count = count($request['input']);

        return Http::response(['embeddings' => array_fill(0, $count, array_fill(0, 4, 0.1))]);
    }]);
    $collection = DB::table('cv_knowledge_collections')->insertGetId(['name' => 'Fixture', 'enabled' => true]);
    $this->actingAs(User::factory()->create()->assignRole('administrator'));

    foreach ([['low', 0], ['high', 9]] as [$name, $priority]) {
        $this->post(route('converse.knowledge.documents.store', $collection), [
            'document' => UploadedFile::fake()->createWithContent("{$name}.md", "# {$name} guide\n\nShared sample content about {$name}.\n"),
        ])->assertRedirect();
        $document = DB::table('cv_knowledge_documents')->where('name', "{$name}.md")->first();
        $this->patch(route('converse.knowledge.documents.update', [$collection, $document->id]), [
            'enabled' => true, 'public' => true, 'priority' => $priority,
        ])->assertRedirect();
        app()->call([new IndexConverseDocument((int) $document->id), 'handle']);
    }
    $high = DB::table('cv_knowledge_documents')->where('name', 'high.md')->first();

    $this->patch(route('converse.knowledge.documents.update', [$collection, $high->id]), [
        'enabled' => true, 'public' => true, 'priority' => 300,
    ])->assertSessionHasErrors('priority');
    $this->patch(route('converse.knowledge.documents.update', [$collection, $high->id]), [
        'enabled' => true, 'public' => true,
    ])->assertSessionHasErrors('priority');

    $this->get(route('converse.knowledge', ['collection' => $collection]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('documents.0.name', 'high.md')->where('documents.0.priority', 9)
            ->where('documents.1.name', 'low.md')->where('documents.1.priority', 0));

    $results = app(RetrieveConverseKnowledge::class)([$collection], 'Shared sample content');
    expect($results->first()->body)->toContain('high');

    $preview = $this->get(route('converse.knowledge.documents.preview', [$collection, $high->id]));
    $preview->assertOk();
    expect($preview->headers->get('Content-Disposition'))->toContain('inline')
        ->and($preview->headers->get('Content-Type'))->toContain('text/markdown');
    $this->get(route('converse.knowledge.documents.preview', [$collection + 999, $high->id]))->assertNotFound();
});
