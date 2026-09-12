<?php

declare(strict_types=1);

use App\Http\Controllers\Administration\AuditLogController;
use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Formulation\FormulaController;
use App\Http\Controllers\Formulation\FormulaImportController;
use App\Http\Controllers\Formulation\FormulaSecurityController;
use App\Http\Controllers\Formulation\FormulaVersionController;
use App\Http\Controllers\Inventory\LotController;
use App\Http\Controllers\Inventory\OpeningStockController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Manufacturing\ManufacturingOrderController;
use App\Http\Controllers\MasterData\PackagingMaterialController;
use App\Http\Controllers\MasterData\ProductController;
use App\Http\Controllers\MasterData\ProductPackagingController;
use App\Http\Controllers\MasterData\RawMaterialController;
use App\Http\Controllers\Planning\MaterialRequestController;
use App\Http\Controllers\Planning\ProductionPlanController;
use App\Http\Controllers\Procurement\GoodsReceiptController;
use App\Http\Controllers\Procurement\VendorController;
use App\Http\Controllers\Quality\QcInspectionController;
use App\Http\Controllers\Settings\FacilityTypeController;
use App\Http\Controllers\Settings\StoreCategoryController;
use App\Http\Controllers\Warehousing\EmployeeAssignmentController;
use App\Http\Controllers\Warehousing\FacilityController;
use App\Http\Controllers\Warehousing\FacilityStoreController;
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

    // ---- Facilities & stores ----------------------------------------------
    Route::resource('facilities', FacilityController::class)->except(['destroy']);
    Route::post('facilities/{facility}/deactivate', [FacilityController::class, 'deactivate'])->name('facilities.deactivate');
    Route::post('facilities/{facility}/activate', [FacilityController::class, 'activate'])->name('facilities.activate');
    Route::post('facilities/{facility}/opening-stock-switch', [FacilityController::class, 'openingStock'])->name('facilities.opening-stock-switch');
    Route::post('facilities/{facility}/stores', [FacilityStoreController::class, 'store'])->name('facilities.stores.store');
    Route::post('facilities/{facility}/employees', [EmployeeAssignmentController::class, 'store'])->name('facilities.employees.store');
    Route::get('facilities/{facility}/opening-stock', [OpeningStockController::class, 'create'])->name('facilities.opening-stock.create');
    Route::post('facilities/{facility}/opening-stock', [OpeningStockController::class, 'store'])->name('facilities.opening-stock.store');

    Route::get('stores/{warehouse}', [FacilityStoreController::class, 'show'])->name('stores.show');
    Route::put('stores/{warehouse}', [FacilityStoreController::class, 'update'])->name('stores.update');
    Route::post('stores/{warehouse}/deactivate', [FacilityStoreController::class, 'deactivate'])->name('stores.deactivate');
    Route::post('stores/{warehouse}/activate', [FacilityStoreController::class, 'activate'])->name('stores.activate');
    Route::delete('stores/{warehouse}', [FacilityStoreController::class, 'destroy'])->name('stores.destroy');

    Route::delete('employee-assignments/{assignment}', [EmployeeAssignmentController::class, 'destroy'])->name('employee-assignments.destroy');
    Route::post('employee-assignments/{assignment}/primary', [EmployeeAssignmentController::class, 'primary'])->name('employee-assignments.primary');

    Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
    Route::get('transfers/create', [StockTransferController::class, 'create'])->name('transfers.create');
    Route::get('transfers/lots', [StockTransferController::class, 'lots'])->name('transfers.lots');
    Route::post('transfers', [StockTransferController::class, 'store'])->name('transfers.store');
    Route::get('transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');
    Route::post('transfers/{transfer}/request', [StockTransferController::class, 'request'])->name('transfers.request');
    Route::post('transfers/{transfer}/approve', [StockTransferController::class, 'approve'])->name('transfers.approve');
    Route::post('transfers/{transfer}/reject', [StockTransferController::class, 'reject'])->name('transfers.reject');
    Route::post('transfers/{transfer}/pack', [StockTransferController::class, 'pack'])->name('transfers.pack');
    Route::post('transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->name('transfers.dispatch');
    Route::post('transfers/{transfer}/transit', [StockTransferController::class, 'transit'])->name('transfers.transit');
    Route::post('transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
    Route::post('transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');

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

    // ---- Planning & Purchase ----------------------------------------------
    Route::get('plans', [ProductionPlanController::class, 'index'])->name('plans.index');
    Route::get('plans/create', [ProductionPlanController::class, 'create'])->name('plans.create');
    Route::post('plans', [ProductionPlanController::class, 'store'])->name('plans.store');
    Route::get('plans/{plan}', [ProductionPlanController::class, 'show'])->name('plans.show');
    Route::post('plans/{plan}/check', [ProductionPlanController::class, 'check'])->name('plans.check');
    Route::post('plans/{plan}/requests', [ProductionPlanController::class, 'requests'])->name('plans.requests');
    Route::post('plans/{plan}/cancel', [ProductionPlanController::class, 'cancel'])->name('plans.cancel');

    Route::get('material-requests', [MaterialRequestController::class, 'index'])->name('material-requests.index');
    Route::get('material-requests/{materialRequest}', [MaterialRequestController::class, 'show'])->name('material-requests.show');
    Route::get('material-requests/{materialRequest}/pdf', [MaterialRequestController::class, 'pdf'])->name('material-requests.pdf');
    Route::post('material-requests/{materialRequest}/cancel', [MaterialRequestController::class, 'cancel'])->name('material-requests.cancel');

    Route::post('products/{product}/packaging', [ProductPackagingController::class, 'store'])->name('products.packaging.store');
    Route::delete('products/{product}/packaging/{line}', [ProductPackagingController::class, 'destroy'])->name('products.packaging.destroy');

    // ---- Manufacturing ----------------------------------------------------
    Route::get('manufacturing', [ManufacturingOrderController::class, 'index'])->name('manufacturing.index');
    Route::post('plans/{plan}/manufacturing', [ManufacturingOrderController::class, 'store'])->name('manufacturing.store');
    Route::get('manufacturing/{order}', [ManufacturingOrderController::class, 'show'])->name('manufacturing.show');
    Route::post('manufacturing/{order}/approve', [ManufacturingOrderController::class, 'approve'])->name('manufacturing.approve');
    Route::post('manufacturing/{order}/start', [ManufacturingOrderController::class, 'start'])->name('manufacturing.start');
    Route::post('manufacturing/{order}/complete', [ManufacturingOrderController::class, 'complete'])->name('manufacturing.complete');
    Route::post('manufacturing/{order}/cancel', [ManufacturingOrderController::class, 'cancel'])->name('manufacturing.cancel');

    // ---- Formulations -----------------------------------------------------
    // The list and the PIN screens need only formula.view. Anything that
    // would load a recipe is additionally behind the formula.unlocked
    // middleware, which sends a locked user to verify their PIN first.
    Route::get('formulas', [FormulaController::class, 'index'])->name('formulas.index');

    Route::get('formulas/unlock', [FormulaSecurityController::class, 'showUnlock'])->name('formulas.unlock');
    Route::post('formulas/unlock', [FormulaSecurityController::class, 'unlock'])
        ->middleware('throttle:10,1')->name('formulas.verify');
    Route::post('formulas/lock', [FormulaSecurityController::class, 'lock'])->name('formulas.lock');
    Route::get('formulas/pin', [FormulaSecurityController::class, 'editPin'])->name('formulas.pin.edit');
    Route::post('formulas/pin', [FormulaSecurityController::class, 'updatePin'])
        ->middleware('throttle:6,1')->name('formulas.pin.update');

    Route::middleware('formula.unlocked')->group(function (): void {
        Route::get('formulas/import', [FormulaImportController::class, 'create'])->name('formulas.imports.create');
        Route::post('formulas/import/preview', [FormulaImportController::class, 'preview'])->name('formulas.imports.preview');
        Route::post('formulas/import', [FormulaImportController::class, 'store'])->name('formulas.imports.store');
        Route::delete('formulas/import', [FormulaImportController::class, 'destroy'])->name('formulas.imports.destroy');

        Route::get('formulas/create', [FormulaController::class, 'create'])->name('formulas.create');
        Route::post('formulas', [FormulaController::class, 'store'])->name('formulas.store');
        Route::get('formulas/{formula}', [FormulaController::class, 'show'])->name('formulas.show');
        Route::get('formulas/{formula}/edit', [FormulaController::class, 'edit'])->name('formulas.edit');
        Route::put('formulas/{formula}', [FormulaController::class, 'update'])->name('formulas.update');
        Route::delete('formulas/{formula}', [FormulaController::class, 'destroy'])->name('formulas.destroy');
        Route::post('formulas/{formula}/archive', [FormulaController::class, 'archive'])->name('formulas.archive');
        Route::post('formulas/{formula}/restore', [FormulaController::class, 'restore'])->name('formulas.restore');

        Route::post('formulas/{formula}/versions', [FormulaVersionController::class, 'store'])->name('formulas.versions.store');
        Route::post('formulas/{formula}/versions/{version}/activate', [FormulaVersionController::class, 'activate'])
            ->scopeBindings()->name('formulas.versions.activate');
        Route::delete('formulas/{formula}/versions/{version}', [FormulaVersionController::class, 'destroy'])
            ->scopeBindings()->name('formulas.versions.destroy');
    });

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
    Route::get('settings/store-categories', [StoreCategoryController::class, 'index'])->name('store-categories.index');
    Route::post('settings/store-categories', [StoreCategoryController::class, 'store'])->name('store-categories.store');
    Route::put('settings/store-categories/{storeCategory}', [StoreCategoryController::class, 'update'])->name('store-categories.update');
    Route::get('settings/facility-types', [FacilityTypeController::class, 'index'])->name('facility-types.index');
    Route::post('settings/facility-types', [FacilityTypeController::class, 'store'])->name('facility-types.store');
    Route::put('settings/facility-types/{facilityType}', [FacilityTypeController::class, 'update'])->name('facility-types.update');

    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('audit/{auditLog}', [AuditLogController::class, 'show'])
        ->whereNumber('auditLog')->name('audit.show');
});

require __DIR__.'/settings.php';
