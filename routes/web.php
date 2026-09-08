<?php

declare(strict_types=1);

use App\Http\Controllers\Administration\AuditLogController;
use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterData\PackagingMaterialController;
use App\Http\Controllers\MasterData\ProductController;
use App\Http\Controllers\MasterData\RawMaterialController;
use App\Http\Controllers\Procurement\VendorController;
use App\Http\Controllers\Warehousing\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

/*
|--------------------------------------------------------------------------
| The ERP
|--------------------------------------------------------------------------
|
| Every route below requires an authenticated, verified and active account.
| Authorisation beyond that is decided per action by a policy, not by the
| route, so that the rule lives next to the model it protects.
|
*/

Route::middleware(['auth', 'verified'])->group(function (): void {

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // ---- Master data ------------------------------------------------------
    Route::resource('warehouses', WarehouseController::class);

    Route::resource('raw-materials', RawMaterialController::class)
        ->parameters(['raw-materials' => 'raw_material']);

    Route::resource('packaging-materials', PackagingMaterialController::class)
        ->parameters(['packaging-materials' => 'packaging_material']);

    Route::resource('products', ProductController::class)
        ->parameters(['products' => 'product']);

    Route::resource('vendors', VendorController::class);

    // ---- Administration ---------------------------------------------------
    Route::resource('users', UserController::class);
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');

    // The audit trail is readable and nothing else. There is no route that
    // writes to it, by design.
    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('audit/{auditLog}', [AuditLogController::class, 'show'])->name('audit.show');
});

require __DIR__.'/settings.php';
