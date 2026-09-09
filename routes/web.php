<?php

declare(strict_types=1);

use App\Http\Controllers\Administration\AuditLogController;
use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\LotController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\MasterData\PackagingMaterialController;
use App\Http\Controllers\MasterData\ProductController;
use App\Http\Controllers\MasterData\RawMaterialController;
use App\Http\Controllers\Procurement\GoodsReceiptController;
use App\Http\Controllers\Procurement\VendorController;
use App\Http\Controllers\Quality\QcInspectionController;
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

    // ---- Store: receiving, quality, stock ---------------------------------
    Route::resource('goods-receipts', GoodsReceiptController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['goods-receipts' => 'goodsReceipt']);
    Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->name('goods-receipts.post');
    Route::post('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('goods-receipts.cancel');

    Route::get('qc', [QcInspectionController::class, 'index'])->name('qc.index');
    Route::get('qc/{qcInspection}', [QcInspectionController::class, 'show'])->name('qc.show');
    Route::post('qc/{qcInspection}/approve', [QcInspectionController::class, 'approve'])->name('qc.approve');
    Route::post('qc/{qcInspection}/reject', [QcInspectionController::class, 'reject'])->name('qc.reject');
    Route::post('qc/{qcInspection}/hold', [QcInspectionController::class, 'hold'])->name('qc.hold');

    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('lots', [LotController::class, 'index'])->name('lots.index');
    Route::get('lots/{lot}', [LotController::class, 'show'])->name('lots.show');
    Route::get('lots/{lot}/sticker', [QcInspectionController::class, 'sticker'])->name('lots.sticker');

    // ---- Administration ---------------------------------------------------
    Route::resource('users', UserController::class);
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');

    // whereNumber: the role model comes from a package, so nothing in this
    // application declares its key type. Constraining it here means the router
    // rejects a non-numeric id outright, and the generated frontend route
    // helpers are typed as numbers rather than falling back to strings.
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])
        ->whereNumber('role')->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])
        ->whereNumber('role')->name('roles.update');

    // The audit trail is readable and nothing else. There is no route that
    // writes to it, by design.
    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('audit/{auditLog}', [AuditLogController::class, 'show'])
        ->whereNumber('auditLog')->name('audit.show');
});

require __DIR__.'/settings.php';
