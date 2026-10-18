<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Not under /admin: agents may open it for their own performance row.
    // Middleware is the first gate; ReportPolicy (per tab) the second.
    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('permission:reports.view|reports.view-own')
        ->name('reports.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/tickets.php';
require __DIR__.'/replies.php';
require __DIR__.'/admin.php';
require __DIR__.'/notifications.php';
