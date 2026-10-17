<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ReplyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('tickets/{ticket}/replies', [ReplyController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('tickets.replies.store');

    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download');
});
