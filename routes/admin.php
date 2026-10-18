<?php

use App\Http\Controllers\Admin\BusinessHourController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\SlaPolicyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('sla-policies', [SlaPolicyController::class, 'index'])->name('sla-policies.index');
    Route::put('sla-policies', [SlaPolicyController::class, 'update'])->name('sla-policies.update');

    Route::get('business-hours', [BusinessHourController::class, 'index'])->name('business-hours.index');
    Route::put('business-hours', [BusinessHourController::class, 'update'])->name('business-hours.update');

    Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
});
