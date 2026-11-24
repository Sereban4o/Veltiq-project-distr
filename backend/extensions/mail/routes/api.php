<?php

/*
| Mail module routes. The module owns the whole mail surface: account settings,
| shared mailbox, folders/rules, contacts, signatures, templates, messages,
| drafts, threads and attachments. Gated by `extension.enabled:mail`.
*/

use App\Extensions\Mail\Http\Controllers\MailAccountController;
use App\Extensions\Mail\Http\Controllers\MailContactController;
use App\Extensions\Mail\Http\Controllers\MailFolderController;
use App\Extensions\Mail\Http\Controllers\MailMessageController;
use App\Extensions\Mail\Http\Controllers\MailRuleController;
use App\Extensions\Mail\Http\Controllers\MailSignatureController;
use App\Extensions\Mail\Http\Controllers\MailTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::prefix('mail')->middleware('extension.enabled:mail')->group(function () {
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/settings', [MailAccountController::class, 'show']);
            Route::put('/settings', [MailAccountController::class, 'update']);
            Route::post('/settings/test', [MailAccountController::class, 'test']);
            Route::post('/sync', [MailAccountController::class, 'sync']);

            // Shared mailbox (team inbox) membership
            Route::get('/accounts', [MailAccountController::class, 'index']);
            Route::get('/settings/members', [MailAccountController::class, 'members']);
            Route::post('/settings/members', [MailAccountController::class, 'addMember']);
            Route::delete('/settings/members/{user}', [MailAccountController::class, 'removeMember'])->whereNumber('user');

            // Custom folders + filter rules
            Route::get('/folders', [MailFolderController::class, 'index']);
            Route::post('/folders', [MailFolderController::class, 'store']);
            Route::post('/folders/from-sender', [MailFolderController::class, 'fromSender']);
            Route::patch('/folders/{folder}', [MailFolderController::class, 'update'])->whereNumber('folder');
            Route::delete('/folders/{folder}', [MailFolderController::class, 'destroy'])->whereNumber('folder');

            Route::get('/rules', [MailRuleController::class, 'index']);
            Route::post('/rules/reorder', [MailRuleController::class, 'reorder']);
            Route::post('/rules/apply', [MailRuleController::class, 'apply']);
            Route::post('/rules', [MailRuleController::class, 'store']);
            Route::patch('/rules/{rule}', [MailRuleController::class, 'update'])->whereNumber('rule');
            Route::delete('/rules/{rule}', [MailRuleController::class, 'destroy'])->whereNumber('rule');

            // Shared address book
            Route::get('/contacts', [MailContactController::class, 'index']);
            Route::get('/contacts/suggest', [MailContactController::class, 'suggest']);
            Route::post('/contacts', [MailContactController::class, 'store']);
            Route::patch('/contacts/{contact}', [MailContactController::class, 'update'])->whereNumber('contact');
            Route::delete('/contacts/{contact}', [MailContactController::class, 'destroy'])->whereNumber('contact');

            // Signatures
            Route::get('/signatures', [MailSignatureController::class, 'index']);
            Route::post('/signatures', [MailSignatureController::class, 'store']);
            Route::patch('/signatures/{signature}', [MailSignatureController::class, 'update'])->whereNumber('signature');
            Route::delete('/signatures/{signature}', [MailSignatureController::class, 'destroy'])->whereNumber('signature');

            // Templates
            Route::get('/templates', [MailTemplateController::class, 'index']);
            Route::post('/templates', [MailTemplateController::class, 'store']);
            Route::patch('/templates/{template}', [MailTemplateController::class, 'update'])->whereNumber('template');
            Route::delete('/templates/{template}', [MailTemplateController::class, 'destroy'])->whereNumber('template');

            // Empty trash on the mail server, then locally
            Route::delete('/trash', [MailMessageController::class, 'emptyTrash']);

            Route::get('/messages', [MailMessageController::class, 'index']);
            Route::post('/messages', [MailMessageController::class, 'send']);
            Route::post('/messages/bulk', [MailMessageController::class, 'bulk']);
            Route::get('/threads/{threadKey}', [MailMessageController::class, 'thread'])->where('threadKey', '[a-f0-9]{40}');
            Route::post('/drafts', [MailMessageController::class, 'storeDraft']);
            Route::get('/messages/{message}', [MailMessageController::class, 'show'])->whereNumber('message');
            Route::patch('/messages/{message}', [MailMessageController::class, 'update'])->whereNumber('message');
            Route::delete('/messages/{message}', [MailMessageController::class, 'destroy'])->whereNumber('message');
            Route::post('/messages/{message}/contacts', [MailContactController::class, 'importFromMessage'])->whereNumber('message');
            Route::get('/messages/{message}/attachments/{attachment}', [MailMessageController::class, 'downloadAttachment'])
                ->whereNumber('message')->whereNumber('attachment');
        });
    });
});
