<?php

use App\Http\Controllers\ConverseWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/converse/webhooks')->group(function () {
    Route::get('/{provider}', [ConverseWebhookController::class, 'verify'])->whereIn('provider', ['meta', 'whatsapp']);
    Route::post('/{provider}', [ConverseWebhookController::class, 'receive'])->whereIn('provider', ['meta', 'whatsapp']);
});
