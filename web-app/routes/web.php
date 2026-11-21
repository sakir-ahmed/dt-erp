<?php

use App\Enums\WorkspacePermission;
use App\Http\Controllers\ConverseAiConfigurationController;
use App\Http\Controllers\ConverseChannelController;
use App\Http\Controllers\ConverseInboxController;
use App\Http\Controllers\ConverseKnowledgeController;
use App\Http\Controllers\ConverseNotificationController;
use App\Http\Controllers\ConverseOutboundAssetController;
use App\Http\Controllers\ConversePlaygroundController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocalQuickSignInController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserDirectoryController;
use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login/local', LocalQuickSignInController::class)
        ->middleware('throttle:10,1')
        ->name('login.local');
    Route::post('/login/demo', LocalQuickSignInController::class)
        ->middleware('throttle:10,1')
        ->name('login.demo');
    Route::post('/login', [SessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/users', [UserDirectoryController::class, 'index'])
        ->can(WorkspacePermission::ViewUsers->value)
        ->name('users.index');
    Route::post('/users', [UserDirectoryController::class, 'store'])
        ->can(WorkspacePermission::ManageUsers->value)
        ->name('users.store');
    Route::patch('/users/{user}', [UserDirectoryController::class, 'update'])
        ->can(WorkspacePermission::ManageUsers->value)
        ->name('users.update');
    Route::get('/converse/inbox/notifications', ConverseNotificationController::class)
        ->can(WorkspacePermission::ViewConverseInbox->value)
        ->name('converse.inbox.notifications');
    Route::post('/converse/inbox/notifications/{notification}/acknowledge', [ConverseNotificationController::class, 'acknowledge'])
        ->whereNumber('notification')->can(WorkspacePermission::ViewConverseInbox->value)
        ->name('converse.inbox.notifications.acknowledge');
    Route::get('/converse/inbox/{conversation?}', ConverseInboxController::class)
        ->whereNumber('conversation')
        ->can(WorkspacePermission::ViewConverseInbox->value)
        ->name('converse.inbox');
    Route::patch('/converse/inbox/{conversation}', [ConverseInboxController::class, 'update'])
        ->whereNumber('conversation')
        ->can(WorkspacePermission::ViewConverseInbox->value)
        ->can(WorkspacePermission::ManageConverseInbox->value)
        ->name('converse.inbox.update');
    Route::put('/converse/inbox/{conversation}/tags', [ConverseInboxController::class, 'syncTags'])
        ->whereNumber('conversation')
        ->can(WorkspacePermission::ManageConverseInbox->value)
        ->name('converse.inbox.tags.sync');
    Route::put('/converse/inbox/{conversation}/knowledge-scope', [ConverseInboxController::class, 'updateKnowledgeScope'])
        ->whereNumber('conversation')
        ->can(WorkspacePermission::ViewConverseInbox->value)
        ->can(WorkspacePermission::ManageConverseInbox->value)
        ->name('converse.inbox.knowledge-scope.update');
    Route::post('/converse/inbox/broadcast', [ConverseInboxController::class, 'broadcast'])
        ->can(WorkspacePermission::ManageConverseInbox->value)
        ->middleware('throttle:5,1')
        ->name('converse.inbox.broadcast');
    Route::post('/converse/inbox/{conversation}/draft', [ConverseInboxController::class, 'draft'])
        ->whereNumber('conversation')
        ->can(WorkspacePermission::ViewConverseInbox->value)
        ->can(WorkspacePermission::ManageConverseInbox->value)
        ->middleware('throttle:6,1')
        ->name('converse.inbox.draft');
    Route::get('/converse/inbox/{conversation}/attachments/{message}', [ConverseInboxController::class, 'downloadAttachment'])
        ->whereNumber(['conversation', 'message'])->can(WorkspacePermission::ViewConverseInbox->value)
        ->name('converse.inbox.attachments.download');
    Route::post('/converse/inbox/{conversation}/attachments/{message}/retry', [ConverseInboxController::class, 'retryAttachment'])
        ->whereNumber(['conversation', 'message'])->can(WorkspacePermission::ViewConverseInbox->value)
        ->can(WorkspacePermission::ManageConverseInbox->value)->middleware('throttle:15,1')
        ->name('converse.inbox.attachments.retry');
    Route::post('/converse/inbox/{conversation}/reply', [ConverseInboxController::class, 'reply'])
        ->whereNumber('conversation')
        ->can(WorkspacePermission::ViewConverseInbox->value)
        ->can(WorkspacePermission::ManageConverseInbox->value)
        ->middleware('throttle:30,1')
        ->name('converse.inbox.reply');
    Route::middleware('can:'.WorkspacePermission::ManageConverseChannels->value)->group(function () {
        Route::get('/converse/development', [ConversePlaygroundController::class, 'development'])->name('converse.development');
        Route::get('/converse/playground', [ConversePlaygroundController::class, 'index'])->name('converse.playground.index');
        Route::post('/converse/playground', [ConversePlaygroundController::class, 'run'])->middleware('throttle:10,1')->name('converse.playground.run');
        Route::patch('/converse/playground/routing', [ConverseAiConfigurationController::class, 'saveRouting'])->name('converse.playground.routing');
        Route::patch('/converse/playground/providers', [ConverseAiConfigurationController::class, 'saveProviders'])->name('converse.playground.providers');
        Route::patch('/converse/playground/channels/{channel}', [ConverseAiConfigurationController::class, 'saveChannel'])
            ->whereNumber('channel')->name('converse.playground.channel');
        Route::get('/converse/channels', [ConverseChannelController::class, 'index'])->name('converse.channels.index');
        Route::post('/converse/channels', [ConverseChannelController::class, 'store'])->name('converse.channels.store');
        Route::patch('/converse/channels/{channel}', [ConverseChannelController::class, 'update'])->whereNumber('channel')->name('converse.channels.update');
    });

    Route::prefix('converse/knowledge')->middleware('can:'.WorkspacePermission::ManageConverseKnowledge->value)->group(function () {
        Route::get('/', [ConverseKnowledgeController::class, 'index'])->name('converse.knowledge');
        Route::post('/collections', [ConverseKnowledgeController::class, 'saveCollection'])->name('converse.knowledge.collections.store');
        Route::patch('/collections/{collection}', [ConverseKnowledgeController::class, 'saveCollection'])->whereNumber('collection')->name('converse.knowledge.collections.update');
        Route::post('/collections/{collection}/entries', [ConverseKnowledgeController::class, 'saveEntry'])->whereNumber('collection')->name('converse.knowledge.entries.store');
        Route::post('/collections/{collection}/outbound-assets', [ConverseOutboundAssetController::class, 'upload'])
            ->whereNumber('collection')->middleware('throttle:12,1')->name('converse.knowledge.outbound-assets.store');
        Route::patch('/collections/{collection}/outbound-assets/{asset}', [ConverseOutboundAssetController::class, 'update'])
            ->whereNumber(['collection', 'asset'])->name('converse.knowledge.outbound-assets.update');
        Route::post('/collections/{collection}/documents', [ConverseKnowledgeController::class, 'uploadDocument'])
            ->whereNumber('collection')->middleware('throttle:12,1')->name('converse.knowledge.documents.store');
        Route::get('/collections/{collection}/documents/{document}/preview', [ConverseKnowledgeController::class, 'showDocument'])
            ->whereNumber(['collection', 'document'])->name('converse.knowledge.documents.preview');
        Route::patch('/collections/{collection}/documents/{document}', [ConverseKnowledgeController::class, 'updateDocument'])
            ->whereNumber(['collection', 'document'])->name('converse.knowledge.documents.update');
        Route::post('/collections/{collection}/documents/reindex/{document?}', [ConverseKnowledgeController::class, 'reindexDocuments'])
            ->whereNumber(['collection', 'document'])->name('converse.knowledge.documents.reindex');
        Route::patch('/collections/{collection}/entries/{entry}', [ConverseKnowledgeController::class, 'saveEntry'])->whereNumber(['collection', 'entry'])->name('converse.knowledge.entries.update');
        Route::post('/chat', [ConverseKnowledgeController::class, 'chat'])->middleware('throttle:10,1')->name('converse.knowledge.chat');
        Route::post('/corrections', [ConverseKnowledgeController::class, 'storeCorrection'])->name('converse.knowledge.corrections.store');
        Route::patch('/corrections/{correction}', [ConverseKnowledgeController::class, 'updateCorrection'])->whereNumber('correction')->name('converse.knowledge.corrections.update');
        Route::delete('/corrections/{correction}', [ConverseKnowledgeController::class, 'destroyCorrection'])->whereNumber('correction')->name('converse.knowledge.corrections.destroy');
    });

    Route::get('/settings', [SettingsController::class, 'index'])
        ->can(WorkspacePermission::ManageSettings->value)
        ->name('settings.index');
    Route::patch('/settings/application', [SettingsController::class, 'updateApplication'])
        ->can(WorkspacePermission::ManageSettings->value)
        ->name('settings.application.update');
    Route::patch('/settings/converse', [SettingsController::class, 'updateConverse'])
        ->can(WorkspacePermission::ManageSettings->value)
        ->name('settings.converse.update');
    Route::patch('/settings/ai-providers', [SettingsController::class, 'updateAiProviders'])
        ->can(WorkspacePermission::ManageSettings->value)
        ->name('settings.ai-providers.update');
    Route::prefix('settings/converse')->middleware('can:'.WorkspacePermission::ManageSettings->value)
        ->middleware('can:'.WorkspacePermission::ManageConverseChannels->value)->name('settings.converse.')->group(function () {
            Route::patch('/providers', [ConverseAiConfigurationController::class, 'saveProviders'])->name('providers');
            Route::patch('/routing', [ConverseAiConfigurationController::class, 'saveRouting'])->name('routing');
            Route::patch('/channels/{channel}', [ConverseAiConfigurationController::class, 'saveChannel'])
                ->whereNumber('channel')->name('channel');
        });

    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});

Route::post('/locale', function (UpdateLocaleRequest $request) {
    $request->session()->put('locale', $request->validated('locale'));

    return back(fallback: route($request->user() ? 'dashboard' : 'login'));
})->name('locale.update');

Route::fallback(fn () => abort(404));
