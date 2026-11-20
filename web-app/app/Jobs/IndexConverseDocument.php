<?php

namespace App\Jobs;

use App\Actions\ConverseAiSettings;
use App\Actions\DetectConverseLanguage;
use App\Actions\EmbedConverseText;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

class IndexConverseDocument implements ShouldQueue
{
    use Queueable;

    public int $timeout = 110;

    public function __construct(public int $documentId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->documentId))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(ConverseAiSettings $configuration, EmbedConverseText $embed, DetectConverseLanguage $languages): void
    {
        $document = DB::table('cv_knowledge_documents')->find($this->documentId);
        if ($document?->status !== 'queued') {
            return;
        }

        $model = $configuration->get()['embedding_model'];
        try {
            if (! is_string($model) || $model === '') {
                throw new DomainException('Configure a local embedding model in the Playground.');
            }

            $text = $this->extract(Storage::disk('local')->path($document->path), $document->format);
            if (! mb_check_encoding($text, 'UTF-8')) {
                throw new DomainException('The extracted text must use UTF-8 encoding.');
            }

            $text = trim(preg_replace('/[\p{Z}\t]+/u', ' ', $text) ?? '');
            $chunks = $this->chunks($text, $languages);
            if ($chunks === [] || count($chunks) > 60) {
                throw new DomainException('The file must contain searchable text within the extraction limit.');
            }

            $entries = [];
            foreach (array_chunk($chunks, 10) as $batch) {
                $vectors = $embed(array_column($batch, 'text'), $model);
                if ($vectors === null) {
                    throw new DomainException('The local embedding model did not return usable vectors.');
                }
                foreach ($batch as $index => $chunk) {
                    $entries[] = [
                        'document_id' => $document->id, 'collection_id' => $document->collection_id,
                        'kind' => 'document', 'language' => $chunk['language'], 'title' => $document->name,
                        'body' => $chunk['text'], 'enabled' => true, 'public' => true, 'embedding_model' => $model,
                        'embedding' => json_encode($vectors[$index], JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }

            $language = $languages->overall(array_column($chunks, 'language'));
            DB::transaction(function () use ($document, $entries, $language): void {
                $current = DB::table('cv_knowledge_documents')->where('id', $document->id)->lockForUpdate()->first();
                if ($current?->path !== $document->path || $current->status !== 'queued') {
                    return;
                }
                DB::table('cv_knowledge_entries')->where('document_id', $document->id)->delete();
                DB::table('cv_knowledge_entries')->insert($entries);
                DB::table('cv_knowledge_documents')->where('id', $document->id)
                    ->update(['status' => 'indexed', 'language' => $language, 'error' => null, 'updated_at' => now()]);
            });
        } catch (DomainException|\JsonException|ProcessTimedOutException $exception) {
            DB::table('cv_knowledge_documents')->where('id', $document->id)->where('path', $document->path)->where('status', 'queued')
                ->update(['status' => 'failed', 'error' => $exception instanceof DomainException ? $exception->getMessage() : 'The document could not be parsed within its limits.', 'updated_at' => now()]);
        }
    }

    /** @return list<array{text: string, language: string}> */
    private function chunks(string $text, DetectConverseLanguage $languages): array
    {
        $paragraphs = preg_split('/\R\s*\R|\R(?=#{1,6}\s)/u', $text) ?: [];
        $chunks = [];
        $pending = '';
        $language = 'unknown';
        foreach ($paragraphs as $paragraph) {
            foreach (mb_str_split(trim($paragraph), 900) as $part) {
                if ($part === '') {
                    continue;
                }
                $detected = $languages->document($part);
                if ($pending !== '' && ($detected !== $language || mb_strlen($pending) + mb_strlen($part) + 2 > 900)) {
                    $chunks[] = ['text' => $pending, 'language' => $language];
                    $pending = '';
                }
                $language = $detected;
                $pending = $pending === '' ? $part : $pending."\n\n".$part;
            }
        }
        if ($pending !== '') {
            $chunks[] = ['text' => $pending, 'language' => $language];
        }

        return $chunks;
    }

    private function extract(string $path, string $format): string
    {
        return match ($format) {
            'md', 'txt', 'csv' => $this->read($path),
            'json' => json_encode(json_decode($this->read($path), true, flags: JSON_THROW_ON_ERROR), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'docx', 'xlsx' => $this->office($path, $format),
            'pdf' => $this->pdf($path),
            default => throw new DomainException('Unsupported document format.'),
        };
    }

    private function read(string $path): string
    {
        $text = is_readable($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new DomainException('The uploaded file could not be read.');
        }

        return $text;
    }

    private function pdf(string $path): string
    {
        $tool = (new ExecutableFinder)->find('pdftotext');
        if ($tool === null) {
            throw new DomainException('Install Poppler pdftotext to index PDF files.');
        }
        $process = new Process([$tool, '-enc', 'UTF-8', '-l', '100', $path, '-'], timeout: 8);
        if ($process->run() !== 0) {
            throw new DomainException('The PDF could not be converted to searchable text.');
        }

        return $process->getOutput();
    }

    private function office(string $path, string $format): string
    {
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            throw new DomainException('The Office document could not be opened.');
        }

        try {
            if ($format === 'docx') {
                $xml = $this->officeXml($archive, 'word/document.xml');
                $lines = [];
                foreach ($xml?->xpath('//*[local-name()="p"]') ?: [] as $paragraph) {
                    $lines[] = implode('', array_map(strval(...), $paragraph->xpath('.//*[local-name()="t"]') ?: []));
                }

                return implode("\n", $lines);
            }

            $strings = [];
            foreach ($this->officeXml($archive, 'xl/sharedStrings.xml')?->xpath('//*[local-name()="si"]') ?: [] as $part) {
                $strings[] = implode('', array_map(strval(...), $part->xpath('.//*[local-name()="t"]') ?: []));
            }

            $rows = [];
            for ($index = 0; $index < min($archive->numFiles, 250); $index++) {
                $name = $archive->getNameIndex($index);
                if (! is_string($name) || ! preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#D', $name)) {
                    continue;
                }
                foreach ($this->officeXml($archive, $name)?->xpath('//*[local-name()="row"]') ?: [] as $row) {
                    $cells = [];
                    foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                        $value = (string) (($cell->xpath('./*[local-name()="v"]') ?: [])[0] ?? '');
                        $cells[] = match ((string) $cell['t']) {
                            's' => $strings[(int) $value] ?? '',
                            'inlineStr' => implode('', array_map(strval(...), $cell->xpath('.//*[local-name()="t"]') ?: [])),
                            default => $value,
                        };
                    }
                    $rows[] = implode(' | ', $cells);
                    if (count($rows) >= 1000) {
                        throw new DomainException('The spreadsheet exceeds the extraction limit.');
                    }
                }
            }

            return implode("\n", $rows);
        } finally {
            $archive->close();
        }
    }

    private function officeXml(ZipArchive $archive, string $name): ?SimpleXMLElement
    {
        $entry = $archive->statName($name);
        if ($entry === false) {
            return null;
        }
        if ($entry['size'] > 1_000_000) {
            throw new DomainException('The Office document exceeds the extraction limit.');
        }

        $content = $archive->getFromName($name);
        $xml = is_string($content) && ! str_contains($content, '<!DOCTYPE')
            ? @simplexml_load_string($content, options: LIBXML_NONET)
            : false;
        if ($xml === false) {
            throw new DomainException('The Office document contains unsupported XML.');
        }

        return $xml;
    }
}
