<?php

use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('tickets/{ticket}/edit', [TicketController::class, 'edit'])->name('tickets.edit');
    Route::put('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
    Route::patch('tickets/{ticket}/status', [TicketController::class, 'changeStatus'])->name('tickets.change-status');
});
