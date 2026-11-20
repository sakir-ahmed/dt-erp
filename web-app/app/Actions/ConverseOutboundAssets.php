<?php

namespace App\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConverseOutboundAssets
{
    public const int MAX_UPLOAD_KIB = 11718;

    public function store(UploadedFile $file, int $owner, ?int $collection = null, ?int $conversation = null, ?string $title = null): object
    {
        $types = ['application/pdf' => ['pdf', 12_000_000], 'image/jpeg' => ['jpg', 5_000_000], 'image/png' => ['png', 5_000_000]];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $bytes = filesize($file->getPathname());
        if (! is_string($mime) || ! isset($types[$mime]) || ! is_int($bytes) || $bytes <= 0 || $bytes > $types[$mime][1]) {
            throw ValidationException::withMessages(['attachment' => __('workspace.converse_attachment_invalid')]);
        }

        $digest = hash_file('sha256', $file->getPathname());
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $name = mb_substr($name ?: 'attachment', 0, 110).'.'.$types[$mime][0];
        $path = $file->storeAs('converse/outbound', Str::uuid().'.'.$types[$mime][0], 'local');
        abort_unless(is_string($path), 500);
        try {
            $id = DB::table('cv_outbound_assets')->insertGetId([
                'owner_id' => $owner, 'collection_id' => $collection, 'conversation_id' => $conversation,
                'name' => $name, 'title' => $title ?? $name, 'path' => $path, 'mime' => $mime, 'bytes' => $bytes,
                'digest' => $digest, 'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return DB::table('cv_outbound_assets')->find($id);
    }

    public function catalog(array $collectionIds): Builder
    {
        return DB::table('cv_outbound_assets as asset')->join('cv_knowledge_collections as collection', 'collection.id', '=', 'asset.collection_id')
            ->whereIn('collection.id', $collectionIds)->where('collection.enabled', true)
            ->where('collection.is_demo', false)->where('asset.approved', true)->whereNull('asset.conversation_id');
    }

    public function forConversation(int $asset, int $conversation, ?int $owner = null): ?object
    {
        return DB::table('cv_outbound_assets')->where('id', $asset)->where('conversation_id', $conversation)
            ->when($owner !== null, fn (Builder $query) => $query->where('owner_id', $owner))->first();
    }
}
