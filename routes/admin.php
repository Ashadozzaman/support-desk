<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BusinessHourController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SlaPolicyController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Each group is gated by the permission it needs, not by `role:admin`:
// roles are editable (Step 19e), so a custom role holding e.g. sla.manage
// must reach the SLA pages. Policies still check again inside each action.
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
        Route::get('agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
        Route::get('agents/{agent}/daily-export', [AgentController::class, 'dailyExport'])->name('agents.daily-export');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    });

    Route::middleware('permission:roles.manage')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::get('system-health', [SystemHealthController::class, 'index'])
        ->middleware('permission:monitoring.view')
        ->name('system-health');

    Route::get('audit-log', [AuditLogController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('audit-log');

    Route::middleware('permission:sla.manage')->group(function () {
        Route::get('sla-policies', [SlaPolicyController::class, 'index'])->name('sla-policies.index');
        Route::put('sla-policies', [SlaPolicyController::class, 'update'])->name('sla-policies.update');
    });

    Route::middleware('permission:business-hours.manage')->group(function () {
        Route::get('business-hours', [BusinessHourController::class, 'index'])->name('business-hours.index');
        Route::put('business-hours', [BusinessHourController::class, 'update'])->name('business-hours.update');

        Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
    });

    Route::middleware('permission:imports.manage')->group(function () {
        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('imports/create', [ImportController::class, 'create'])->name('imports.create');
        Route::get('imports/sample', [ImportController::class, 'sample'])->name('imports.sample');
        Route::post('imports', [ImportController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('imports.store');
        Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::get('imports/{import}/errors', [ImportController::class, 'errors'])->name('imports.errors');
    });
});
