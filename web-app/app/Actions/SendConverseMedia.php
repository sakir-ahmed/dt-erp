<?php

namespace App\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendConverseMedia
{
    public function upload(PendingRequest $request, string $base, bool $whatsapp, object $asset): ?string
    {
        $stream = Storage::disk('local')->readStream($asset->path);
        if (! is_resource($stream)) {
            Log::channel('converse')->warning('media upload missing stored file', ['asset_id' => $asset->id, 'path' => $asset->path]);

            return null;
        }

        try {
            $request->attach($whatsapp ? 'file' : 'filedata', $stream, $asset->name, ['Content-Type' => $asset->mime]);
            $response = $whatsapp
                ? $request->post($base.'/media', ['messaging_product' => 'whatsapp', 'type' => $asset->mime])
                : $request->post($base.'/message_attachments', [
                    'message' => json_encode(['attachment' => [
                        'type' => $asset->mime === 'application/pdf' ? 'file' : 'image', 'payload' => ['is_reusable' => true],
                    ]], JSON_THROW_ON_ERROR),
                ]);
        } catch (ConnectionException) {
            Log::channel('converse')->warning('media upload network failure', ['asset_id' => $asset->id, 'channel' => $whatsapp ? 'whatsapp' : 'messenger']);

            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $id = $response->successful() ? $response->json($whatsapp ? 'id' : 'attachment_id') : null;
        if (! is_string($id) || $id === '' || strlen($id) > 255) {
            Log::channel('converse')->warning('media upload rejected', [
                'asset_id' => $asset->id, 'status' => $response->status(),
                'error' => $response->json('error.message'),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return null;
        }

        return $id;
    }
}
