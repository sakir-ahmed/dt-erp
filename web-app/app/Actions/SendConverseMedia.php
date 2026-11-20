<?php

namespace App\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Storage;

class SendConverseMedia
{
    public function upload(PendingRequest $request, string $base, bool $whatsapp, object $asset): ?string
    {
        $stream = Storage::disk('local')->readStream($asset->path);
        if (! is_resource($stream)) {
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
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $id = $response->successful() ? $response->json($whatsapp ? 'id' : 'attachment_id') : null;

        return is_string($id) && $id !== '' && strlen($id) <= 255 ? $id : null;
    }
}
