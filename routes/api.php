<?php

use App\Http\Controllers\Api\V1\ReplyController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

// REST API v1 (docs/API.md). Bearer tokens only (Sanctum); interactive
// docs at /docs/api. Every route reuses the web app's FormRequests,
// policies and services — authorization is identical to the web UI.
Route::prefix('v1')->name('api.v1.')->group(function () {
    // Keyed by IP: a guessing script gets 6 tries a minute, like the login form.
    Route::post('tokens', [TokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('tokens.store');

    Route::middleware(['auth:sanctum', 'verified', 'throttle:60,1'])->group(function () {
        Route::get('me', [TokenController::class, 'me'])->name('me');
        Route::delete('tokens/current', [TokenController::class, 'destroy'])->name('tokens.destroy');

        Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::put('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
        Route::patch('tickets/{ticket}/status', [TicketController::class, 'changeStatus'])->name('tickets.change-status');
        Route::post('tickets/{ticket}/claim', [TicketController::class, 'claim'])->name('tickets.claim');
        Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');

        Route::get('tickets/{ticket}/replies', [ReplyController::class, 'index'])->name('tickets.replies.index');
        Route::post('tickets/{ticket}/replies', [ReplyController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('tickets.replies.store');

        Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
            ->name('attachments.download');
    });
});
