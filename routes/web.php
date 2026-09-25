<?php

declare(strict_types=1);

use App\Http\Controllers\Administration\AuditLogController;
use App\Http\Controllers\Administration\DataController;
use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\UserController;
use App\Http\Controllers\Approvals\ApprovalController;
use App\Http\Controllers\Contract\ArtworkDocumentController;
use App\Http\Controllers\Contract\ClientArtworkController;
use App\Http\Controllers\Contract\ClientController;
use App\Http\Controllers\Contract\ClientProfitabilityController;
use App\Http\Controllers\Contract\ClientQcSpecController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dispatch\CustomerController;
use App\Http\Controllers\Dispatch\DispatchController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Floor\FloorController;
use App\Http\Controllers\Floor\ParcelController;
use App\Http\Controllers\Formulation\FormulaController;
use App\Http\Controllers\Formulation\FormulaImportController;
use App\Http\Controllers\Formulation\FormulaSecurityController;
use App\Http\Controllers\Formulation\FormulaVersionController;
use App\Http\Controllers\Intelligence\AssistantController;
use App\Http\Controllers\Intelligence\CapacityController;
use App\Http\Controllers\Intelligence\CommandCentreController;
use App\Http\Controllers\Intelligence\ExpiryRiskController;
use App\Http\Controllers\Intelligence\ProductionAnalyticsController;
use App\Http\Controllers\Intelligence\ReorderAdviceController;
use App\Http\Controllers\Intelligence\ScorecardController;
use App\Http\Controllers\Intelligence\SlowMovingStockController;
use App\Http\Controllers\Intelligence\WhatIfController;
use App\Http\Controllers\Inventory\LedgerController;
use App\Http\Controllers\Inventory\LotController;
use App\Http\Controllers\Inventory\OpeningStockController;
use App\Http\Controllers\Inventory\OpeningStockSheetController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockCountController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Management\ManagementController;
use App\Http\Controllers\Manufacturing\ManufacturingOrderController;
use App\Http\Controllers\MasterData\PackagingMaterialController;
use App\Http\Controllers\MasterData\ProductArtworkController;
use App\Http\Controllers\MasterData\ProductController;
use App\Http\Controllers\MasterData\ProductPackagingController;
use App\Http\Controllers\MasterData\RawMaterialController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnlineOrders\BrandController;
use App\Http\Controllers\OnlineOrders\ListingController;
use App\Http\Controllers\OnlineOrders\OnlineOrderController;
use App\Http\Controllers\OnlineOrders\ReturnController;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// The address itself is the way in: straight to the sign-in card, or to the
// dashboard for someone already signed in. There is no public front page;
// this ERP has nothing to show anyone who cannot sign in.
Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'dashboard' : 'login'))->name('home');

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
    Route::get('command-centre', CommandCentreController::class)->name('command-centre');
    Route::get('scorecards', ScorecardController::class)->name('scorecards');
    Route::get('assistant', [AssistantController::class, 'index'])->name('assistant.index');
    Route::post('assistant/ask', [AssistantController::class, 'ask'])
        ->middleware('throttle:'.(int) config('erp.ai.assistant.rate_per_minute', 20).',1')->name('assistant.ask');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::post('documents/{document}/approve', [DocumentController::class, 'approve'])->name('documents.approve');
    Route::post('documents/{document}/withdraw', [DocumentController::class, 'withdraw'])->name('documents.withdraw');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

    Route::post('ledger/{transaction}/reverse', [LedgerController::class, 'reverse'])->name('ledger.reverse');

    // The management view on a phone.
    Route::prefix('m')->name('management.')->controller(ManagementController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('production', 'production')->name('production');
        Route::get('materials', 'materials')->name('materials');
        Route::get('ordering', 'ordering')->name('ordering');
        Route::get('formulas', 'formulas')->name('formulas');
        Route::get('clients', 'clients')->name('clients');
        Route::get('batches', 'batches')->name('batches');
        Route::get('billing', 'billing')->name('billing');
    });

    // The mobile floor mode.
    Route::get('floor', [FloorController::class, 'index'])->name('floor.index');
    Route::get('floor/scan', [FloorController::class, 'scan'])->name('floor.scan');
    Route::post('floor/lookup', [FloorController::class, 'lookup'])->name('floor.lookup');
    Route::get('floor/issue/{order}', [FloorController::class, 'issue'])->name('floor.issue');
    Route::get('floor/production', [FloorController::class, 'production'])->name('floor.production');
    Route::post('floor/photo', [FloorController::class, 'photo'])->name('floor.photo');
    Route::get('floor/pack', [ParcelController::class, 'pack'])->name('floor.pack');
    Route::post('floor/pack', [ParcelController::class, 'scan'])->name('floor.pack.scan');
    Route::get('floor/handover', [ParcelController::class, 'handover'])->name('floor.handover');
    Route::post('floor/handover', [ParcelController::class, 'storeHandover'])->name('floor.handover.store');
    Route::get('floor/return', [ReturnController::class, 'floor'])->name('floor.return');

    Route::get('counts', [StockCountController::class, 'index'])->name('counts.index');
    Route::post('counts', [StockCountController::class, 'store'])->name('counts.store');
    Route::get('counts/{count}', [StockCountController::class, 'show'])->name('counts.show');
    Route::post('counts/{count}/lines', [StockCountController::class, 'line'])->name('counts.line');
    Route::post('counts/{count}/submit', [StockCountController::class, 'submit'])->name('counts.submit');
    Route::post('counts/{count}/approve', [StockCountController::class, 'approve'])->name('counts.approve');
    Route::post('counts/{count}/cancel', [StockCountController::class, 'cancel'])->name('counts.cancel');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    // ---- Facilities & stores ----------------------------------------------
    Route::resource('facilities', FacilityController::class)->except(['destroy']);
    Route::post('facilities/{facility}/deactivate', [FacilityController::class, 'deactivate'])->name('facilities.deactivate');
    Route::post('facilities/{facility}/activate', [FacilityController::class, 'activate'])->name('facilities.activate');
    Route::post('facilities/{facility}/opening-stock-switch', [FacilityController::class, 'openingStock'])->name('facilities.opening-stock-switch');
    Route::post('facilities/{facility}/stores', [FacilityStoreController::class, 'store'])->name('facilities.stores.store');
    Route::post('facilities/{facility}/employees', [EmployeeAssignmentController::class, 'store'])->name('facilities.employees.store');
    Route::get('facilities/{facility}/opening-stock', [OpeningStockController::class, 'create'])->name('facilities.opening-stock.create');
    Route::post('facilities/{facility}/opening-stock', [OpeningStockController::class, 'store'])->name('facilities.opening-stock.store');
    // The counting sheet for one store: the template to fill in, and the
    // filled-in sheet matched to the masters before it is booked.
    Route::get('stores/{warehouse}/opening-stock/template', [OpeningStockSheetController::class, 'template'])->name('stores.opening-stock.template');
    Route::post('stores/{warehouse}/opening-stock/parse', [OpeningStockSheetController::class, 'parse'])->name('stores.opening-stock.parse');

    Route::get('stores/{warehouse}', [FacilityStoreController::class, 'show'])->name('stores.show');
    Route::get('stores/{warehouse}/labels', [FacilityStoreController::class, 'labels'])->name('stores.labels');
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
    Route::get('transfers/{transfer}/challan', [StockTransferController::class, 'challan'])->name('transfers.challan');
    Route::get('transfers/{transfer}/transport-document', [StockTransferController::class, 'transportDocument'])->name('transfers.document');
    Route::post('transfers/{transfer}/scan', [StockTransferController::class, 'scan'])->name('transfers.scan');
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

    Route::post('raw-materials/quick', [RawMaterialController::class, 'quick'])->name('raw-materials.quick');
    Route::post('packaging-materials/quick', [PackagingMaterialController::class, 'quick'])->name('packaging-materials.quick');
    Route::resource('raw-materials', RawMaterialController::class)
        ->parameters(['raw-materials' => 'raw_material']);

    Route::resource('packaging-materials', PackagingMaterialController::class)
        ->parameters(['packaging-materials' => 'packaging_material']);

    Route::resource('products', ProductController::class)
        ->parameters(['products' => 'product']);

    Route::post('vendors/quick', [VendorController::class, 'quick'])->name('vendors.quick');
    Route::resource('vendors', VendorController::class);

    // Dispatch: finished goods leaving against a tax invoice, and who they go to.
    Route::get('dispatches', [DispatchController::class, 'index'])->name('dispatches.index');
    Route::get('dispatches/create', [DispatchController::class, 'create'])->name('dispatches.create');
    Route::get('dispatches/lots', [DispatchController::class, 'lots'])->name('dispatches.lots');
    Route::post('dispatches', [DispatchController::class, 'store'])->name('dispatches.store');
    Route::get('dispatches/{dispatch}', [DispatchController::class, 'show'])->whereNumber('dispatch')->name('dispatches.show');
    Route::post('dispatches/{dispatch}/invoice', [DispatchController::class, 'invoice'])->name('dispatches.invoice');
    Route::post('dispatches/{dispatch}/attachments', [DispatchController::class, 'attach'])->name('dispatches.attachments.store');
    Route::get('dispatches/{dispatch}/attachments/{attachment}', [DispatchController::class, 'attachment'])->name('dispatches.attachments.show');
    Route::get('dispatches/{dispatch}/einvoice', [DispatchController::class, 'einvoice'])->name('dispatches.einvoice');
    Route::get('dispatches/{dispatch}/challan', [DispatchController::class, 'challan'])->name('dispatches.challan');
    Route::post('dispatches/{dispatch}/dispatch', [DispatchController::class, 'dispatch'])->name('dispatches.dispatch');
    Route::post('dispatches/{dispatch}/deliver', [DispatchController::class, 'deliver'])->name('dispatches.deliver');
    Route::post('dispatches/{dispatch}/cancel', [DispatchController::class, 'cancel'])->name('dispatches.cancel');
    Route::resource('customers', CustomerController::class)->except(['show', 'destroy']);

    // Online orders: the marketplaces' labels, from the agency's upload to
    // the courier's pickup.
    Route::get('online-orders', [OnlineOrderController::class, 'index'])->name('online-orders.index');
    Route::get('online-orders/upload', [OnlineOrderController::class, 'create'])->name('online-orders.create');
    Route::post('online-orders', [OnlineOrderController::class, 'store'])->name('online-orders.store');
    Route::get('online-orders/sku-mapping', [ListingController::class, 'index'])->name('listings.index');
    Route::post('online-orders/sku-mapping', [ListingController::class, 'store'])->name('listings.store');
    Route::patch('online-orders/sku-mapping/{listing}', [ListingController::class, 'update'])->name('listings.update');
    Route::get('online-orders/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::patch('online-orders/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
    Route::get('online-orders/files/{file}', [OnlineOrderController::class, 'file'])->name('online-orders.files.show');
    Route::delete('online-orders/files/{file}', [OnlineOrderController::class, 'removeFile'])->name('online-orders.files.destroy');
    Route::patch('online-orders/parcels/{shipment}', [OnlineOrderController::class, 'correct'])->name('online-orders.parcels.update');
    Route::get('online-orders/lookup', [OnlineOrderController::class, 'lookup'])->name('online-orders.lookup');
    Route::get('online-orders/returns', [ReturnController::class, 'index'])->name('online-orders.returns.index');
    Route::get('online-orders/returns/receive', [ReturnController::class, 'create'])->name('online-orders.returns.create');
    Route::get('online-orders/returns/lookup', [ReturnController::class, 'lookup'])->name('online-orders.returns.lookup');
    Route::post('online-orders/returns', [ReturnController::class, 'store'])->name('online-orders.returns.store');
    Route::patch('online-orders/returns/{return}/claim', [ReturnController::class, 'claim'])->name('online-orders.returns.claim');
    Route::post('online-orders/parcels/{shipment}/cancel', [OnlineOrderController::class, 'cancel'])->name('online-orders.parcels.cancel');
    Route::post('online-orders/parcels/{shipment}/pack', [OnlineOrderController::class, 'packManually'])->name('online-orders.parcels.pack');
    Route::get('online-orders/handover-sheets/{sheet}', [ParcelController::class, 'sheet'])->name('handover-sheets.pdf');
    Route::get('online-orders/{batch}', [OnlineOrderController::class, 'show'])->whereNumber('batch')->name('online-orders.show');
    Route::post('online-orders/{batch}/close', [OnlineOrderController::class, 'close'])->name('online-orders.close');
    Route::post('online-orders/{batch}/hold', [OnlineOrderController::class, 'holdAgain'])->name('online-orders.hold');
    Route::post('online-orders/{batch}/print', [OnlineOrderController::class, 'print'])->name('online-orders.print');

    // Third-party / contract manufacturing clients and what is theirs.
    Route::get('clients/profitability', ClientProfitabilityController::class)->name('clients.profitability');
    Route::resource('clients', ClientController::class);
    Route::post('clients/{client}/artworks', [ClientArtworkController::class, 'store'])->name('clients.artworks.store');
    Route::post('clients/{client}/artworks/{artwork}/status', [ClientArtworkController::class, 'status'])->name('clients.artworks.status');
    Route::get('clients/{client}/artworks/{artwork}/document', [ClientArtworkController::class, 'document'])->name('clients.artworks.document');
    Route::delete('clients/{client}/artworks/{artwork}', [ClientArtworkController::class, 'destroy'])->name('clients.artworks.destroy');
    // The file itself, from a batch page or the floor.
    Route::get('artworks/{artwork}/document', ArtworkDocumentController::class)->name('artworks.document');
    Route::put('clients/{client}/qc-specs/{product}', [ClientQcSpecController::class, 'upsert'])->name('clients.qc-specs.upsert');
    Route::delete('clients/{client}/qc-specs/{spec}', [ClientQcSpecController::class, 'destroy'])->name('clients.qc-specs.destroy');

    // ---- Store: receiving, quality, stock ---------------------------------
    Route::post('goods-receipts/intake', [GoodsReceiptController::class, 'intake'])->name('goods-receipts.intake');
    Route::get('goods-receipts/{goodsReceipt}/document', [GoodsReceiptController::class, 'document'])->name('goods-receipts.document');
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
    Route::get('qc/{qcInspection}/slip', [QcInspectionController::class, 'slip'])->name('qc.slip');

    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('stock/slow-moving', SlowMovingStockController::class)->name('stock.slow-moving');
    Route::get('stock/expiry-risk', ExpiryRiskController::class)->name('stock.expiry-risk');
    Route::get('purchase/reorder-advice', ReorderAdviceController::class)->name('purchase.reorder-advice');
    Route::get('lots', [LotController::class, 'index'])->name('lots.index');
    Route::get('lots/{lot}', [LotController::class, 'show'])->name('lots.show');
    Route::get('lots/{lot}/trace', [LotController::class, 'trace'])->name('lots.trace');
    Route::get('lots/{lot}/sticker', [QcInspectionController::class, 'sticker'])->name('lots.sticker');
    Route::get('lots/{lot}/cartons', [LotController::class, 'cartons'])->name('lots.cartons');
    Route::post('lots/{lot}/cartons', [LotController::class, 'storeCartons'])->name('lots.cartons.store');
    Route::get('lots/{lot}/cartons/print', [LotController::class, 'printCartons'])->name('lots.cartons.print');

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
    Route::post('products/{product}/artworks', [ProductArtworkController::class, 'store'])->name('products.artworks.store');
    Route::post('products/{product}/artworks/{artwork}/status', [ProductArtworkController::class, 'status'])->name('products.artworks.status');
    Route::delete('products/{product}/artworks/{artwork}', [ProductArtworkController::class, 'destroy'])->name('products.artworks.destroy');
    Route::delete('products/{product}/packaging/{line}', [ProductPackagingController::class, 'destroy'])->name('products.packaging.destroy');

    // ---- Manufacturing ----------------------------------------------------
    Route::get('manufacturing', [ManufacturingOrderController::class, 'index'])->name('manufacturing.index');
    Route::post('plans/{plan}/manufacturing', [ManufacturingOrderController::class, 'store'])->name('manufacturing.store');
    Route::get('manufacturing/{order}', [ManufacturingOrderController::class, 'show'])->name('manufacturing.show');
    Route::post('manufacturing/{order}/approve', [ManufacturingOrderController::class, 'approve'])->name('manufacturing.approve');
    Route::post('manufacturing/{order}/start', [ManufacturingOrderController::class, 'start'])->name('manufacturing.start');
    Route::post('manufacturing/{order}/complete', [ManufacturingOrderController::class, 'complete'])->name('manufacturing.complete');
    Route::post('manufacturing/{order}/cancel', [ManufacturingOrderController::class, 'cancel'])->name('manufacturing.cancel');
    Route::put('manufacturing/{order}/terms', [ManufacturingOrderController::class, 'terms'])->name('manufacturing.terms');
    Route::post('manufacturing/{order}/stage', [ManufacturingOrderController::class, 'stage'])->name('manufacturing.stage');
    Route::post('manufacturing/{order}/adjustments', [ManufacturingOrderController::class, 'adjust'])->name('manufacturing.adjust');
    Route::post('manufacturing/{order}/scan', [ManufacturingOrderController::class, 'scan'])->name('manufacturing.scan');
    Route::get('manufacturing/{order}/card', [ManufacturingOrderController::class, 'card'])->name('manufacturing.card');
    Route::get('analytics/production', ProductionAnalyticsController::class)->name('analytics.production');
    Route::get('planning/simulate', WhatIfController::class)->name('planning.simulate');
    Route::get('planning/capacity', CapacityController::class)->name('planning.capacity');

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

    // Clearing what testing left behind, and the worked example. Both are
    // the system administrator's alone.
    Route::get('administration/data', [DataController::class, 'index'])->name('administration.data');
    Route::post('administration/data/clear', [DataController::class, 'clear'])->name('administration.data.clear');
    Route::post('administration/data/demo', [DataController::class, 'fillDemo'])->name('administration.data.demo');
    Route::get('administration/data/download', [DataController::class, 'download'])->name('administration.data.download');
    Route::post('administration/data/restore', [DataController::class, 'restore'])->name('administration.data.restore');

    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('audit/{auditLog}', [AuditLogController::class, 'show'])
        ->whereNumber('auditLog')->name('audit.show');
});

require __DIR__.'/settings.php';
