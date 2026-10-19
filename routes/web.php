<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HelpCentreController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;

// No public landing page: `/` is the login screen. A signed-in user is forwarded
// on to the dashboard by the login route's guest middleware.
Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Help centre (Step 22): published KB articles for every signed-in user.
    Route::get('help', [HelpCentreController::class, 'index'])->name('help.index');
    Route::get('help/{article:slug}', [HelpCentreController::class, 'show'])->name('help.show');

    // Not under /admin: agents may open it for their own performance row.
    // Middleware is the first gate; ReportPolicy (per tab) the second.
    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('permission:reports.view|reports.view-own')
        ->name('reports.index');

    Route::middleware(['permission:reports.export', 'throttle:10,1'])->prefix('reports/export')->name('reports.export.')->group(function () {
        Route::get('summary', [ReportExportController::class, 'summary'])->name('summary');
        Route::get('tickets', [ReportExportController::class, 'tickets'])->name('tickets');
    });

    // Signed + expiring link from the "export ready" notification; the owner
    // check is ReportExportPolicy::download.
    Route::get('reports/exports/{export}/download', [ReportExportController::class, 'download'])
        ->middleware('signed')
        ->name('reports.exports.download');
});

require __DIR__.'/settings.php';
require __DIR__.'/tickets.php';
require __DIR__.'/replies.php';
require __DIR__.'/admin.php';
require __DIR__.'/notifications.php';
