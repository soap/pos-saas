<?php

use App\Modules\Cashier\Http\Controllers\CashierController;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Devices\Http\Controllers\DeviceController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Kitchen\Http\Controllers\KitchenController;
use App\Modules\Kitchen\Http\Controllers\KitchenStationController;
use App\Modules\Ordering\Http\Controllers\DiningTableController;
use App\Modules\Ordering\Http\Controllers\OrderController;
use App\Modules\Payments\Http\Controllers\PaymentAccountController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Tenancy\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| POS SaaS API v1  (prefix /api/v1)
|--------------------------------------------------------------------------
| Headers:
|   Authorization: Bearer <token>
|   X-Store-Id:    <uuid>   (route ที่ใช้ middleware "store")
|   Idempotency-Key: <key>  (route ที่ใช้ middleware "idempotent")
|
| ระบุ tenant (route ที่ใช้ middleware "tenant") ได้ 3 แบบ — ดู TenantResolver:
|   /api/v1/t/{slug}/...                 (แนะนำ)
|   {slug}.<POS_TENANT_BASE_DOMAIN>      (เมื่อเปิดใช้ subdomain)
|   X-Tenant-Id: <uuid>
*/

// route ระดับ tenant/store — ลงทะเบียน 2 ชุด: มี prefix /t/{slug} และไม่มี (subdomain/header)
$tenantRoutes = function () {
    // ส่ง X-Store-Id มาด้วย → ได้ store + permission ของ role ระดับสาขา (เช่น cashier)
    Route::get('context', [TenantController::class, 'context'])->middleware('store:optional');
    Route::get('stores', [TenantController::class, 'stores'])->middleware('perm:stores.view');
    Route::post('stores', [TenantController::class, 'createStore'])->middleware('perm:stores.manage');
    // store:optional → รู้ว่าสาขาไหนกำลังใช้งาน (กันปิดสาขาตัวเอง)
    Route::patch('stores/{id}', [TenantController::class, 'updateStore'])->middleware(['store:optional', 'perm:stores.manage']);

    Route::get('members', [TenantController::class, 'members'])->middleware('perm:members.manage');
    Route::post('members', [TenantController::class, 'addMember'])->middleware('perm:members.manage');
    Route::patch('members/{id}', [TenantController::class, 'updateMember'])->middleware('perm:members.manage');
    Route::delete('members/{id}/roles/{role}', [TenantController::class, 'removeRole'])->middleware('perm:members.manage');

    // บัญชีรับชำระ QR (ระดับ tenant)
    Route::get('payment-accounts', [PaymentAccountController::class, 'index'])->middleware('perm:payments.configure');
    Route::post('payment-accounts', [PaymentAccountController::class, 'store'])->middleware('perm:payments.configure');
    Route::patch('payment-accounts/{id}', [PaymentAccountController::class, 'update'])->middleware('perm:payments.configure');
    Route::put('stores/{id}/qr-payment-account', [PaymentAccountController::class, 'assignToStore'])->middleware('perm:payments.configure');

    // Catalog (ระดับ tenant)
    Route::get('categories', [CatalogController::class, 'categories'])->middleware('perm:catalog.view');
    Route::post('categories', [CatalogController::class, 'createCategory'])->middleware('perm:catalog.manage');
    Route::patch('categories/{id}', [CatalogController::class, 'updateCategory'])->middleware('perm:catalog.manage');
    Route::get('products', [CatalogController::class, 'products'])->middleware('perm:catalog.view');
    Route::post('products', [CatalogController::class, 'createProduct'])->middleware('perm:catalog.manage');
    Route::patch('products/{id}', [CatalogController::class, 'updateProduct'])->middleware('perm:catalog.manage');
    Route::post('products/{id}/images', [CatalogController::class, 'addProductImage'])->middleware('perm:catalog.manage');
    Route::delete('products/{id}/images/{mediaId}', [CatalogController::class, 'removeProductImage'])->whereNumber('mediaId')->middleware('perm:catalog.manage');
    Route::put('products/{id}/primary-image', [CatalogController::class, 'setPrimaryProductImage'])->middleware('perm:catalog.manage');
    Route::get('modifier-groups', [CatalogController::class, 'modifierGroups'])->middleware('perm:catalog.view');
    Route::post('modifier-groups', [CatalogController::class, 'createModifierGroup'])->middleware('perm:catalog.manage');
    // store:optional → role ระดับสาขา (เช่น ครัว) ใช้สิทธิ์ของสาขาที่ส่ง X-Store-Id มาได้
    Route::get('kitchen-stations', [KitchenStationController::class, 'index'])->middleware(['store:optional', 'perm:catalog.view']);
    Route::post('kitchen-stations', [KitchenStationController::class, 'store'])->middleware('perm:catalog.manage');
    Route::patch('kitchen-stations/{id}', [KitchenStationController::class, 'update'])->middleware('perm:catalog.manage');
    Route::patch('modifier-groups/{id}', [CatalogController::class, 'updateModifierGroup'])->middleware('perm:catalog.manage');
    Route::post('modifier-groups/{id}/options', [CatalogController::class, 'addModifierOption'])->middleware('perm:catalog.manage');
    Route::patch('modifier-options/{id}', [CatalogController::class, 'updateModifierOption'])->middleware('perm:catalog.manage');

    // ---------- Store-level ----------
    Route::middleware('store')->group(function () {
        Route::get('menu', [CatalogController::class, 'menu'])->middleware('perm:catalog.view');
        Route::get('store-prices', [CatalogController::class, 'storePrices'])->middleware('perm:catalog.view');
        Route::put('products/{id}/store-price', [CatalogController::class, 'setStorePrice'])->middleware('perm:catalog.manage');
        Route::delete('products/{id}/store-price', [CatalogController::class, 'clearStorePrice'])->middleware('perm:catalog.manage');

        // Orders
        Route::get('orders', [OrderController::class, 'index'])->middleware('perm:orders.view');
        Route::post('orders', [OrderController::class, 'store'])->middleware(['perm:orders.create', 'idempotent:orders.create']);
        Route::get('orders/{id}', [OrderController::class, 'show'])->middleware('perm:orders.view');
        Route::get('orders/{id}/events', [OrderController::class, 'events'])->middleware('perm:orders.view');
        Route::get('orders/{id}/receipt', [OrderController::class, 'receipt'])->middleware('perm:orders.view');
        Route::post('orders/{id}/items', [OrderController::class, 'addItems'])->middleware(['perm:orders.create', 'idempotent:orders.add_items']);
        Route::patch('orders/{id}/items/{item}', [OrderController::class, 'updateItem'])->middleware('perm:orders.create');
        Route::delete('orders/{id}/items/{item}', [OrderController::class, 'removeItem'])->middleware('perm:orders.create');
        Route::post('orders/{id}/discount', [OrderController::class, 'discount'])->middleware('perm:orders.discount');
        Route::post('orders/{id}/cancel', [OrderController::class, 'cancel'])->middleware('perm:orders.cancel');
        Route::post('orders/{id}/move-table', [OrderController::class, 'moveTable'])->middleware('perm:orders.create');

        // Tables (ผังโต๊ะของสาขา)
        Route::get('tables', [DiningTableController::class, 'index'])->middleware('perm:orders.view');
        Route::post('tables', [DiningTableController::class, 'store'])->middleware('perm:stores.manage');
        Route::patch('tables/{id}', [DiningTableController::class, 'update'])->middleware('perm:stores.manage');

        // Kitchen
        Route::post('orders/{id}/send-to-kitchen', [KitchenController::class, 'sendToKitchen'])->middleware(['perm:orders.create', 'idempotent:kitchen.send']);
        Route::get('kitchen/tickets', [KitchenController::class, 'index'])->middleware('perm:kitchen.view');
        Route::get('kitchen/tickets/{id}', [KitchenController::class, 'show'])->middleware('perm:kitchen.view');
        Route::post('kitchen/tickets/{id}/acknowledge', [KitchenController::class, 'acknowledge'])->middleware('perm:kitchen.update');
        Route::post('kitchen/tickets/{id}/ready', [KitchenController::class, 'ready'])->middleware('perm:kitchen.update');
        Route::post('kitchen/tickets/{id}/served', [KitchenController::class, 'served'])->middleware('perm:kitchen.update|orders.create');
        Route::post('kitchen/tickets/{id}/cancel', [KitchenController::class, 'cancel'])->middleware('perm:kitchen.void');

        // Payments
        Route::middleware('throttle:payments')->group(function () {
            Route::post('orders/{id}/payments', [PaymentController::class, 'store'])->middleware(['perm:payments.create', 'idempotent:payments.create']);
            Route::get('payments/{id}', [PaymentController::class, 'show'])->middleware('perm:payments.view');
            Route::post('payments/{id}/cancel', [PaymentController::class, 'cancel'])->middleware('perm:payments.create');
            Route::post('payments/{id}/slip', [PaymentController::class, 'submitSlip'])->middleware(['perm:payments.create', 'idempotent:payments.slip']);
            Route::post('payments/{id}/refunds', [PaymentController::class, 'refund'])->middleware(['perm:payments.refund', 'idempotent:payments.refund']);
        });

        // Cashier
        Route::get('cashier-shifts', [CashierController::class, 'index'])->middleware('perm:cashier.operate');
        Route::get('cashier-shifts/current', [CashierController::class, 'current'])->middleware('perm:cashier.operate');
        Route::get('cashier-shifts/{id}', [CashierController::class, 'show'])->middleware('perm:cashier.operate');
        Route::post('cashier-shifts/open', [CashierController::class, 'open'])->middleware('perm:cashier.operate');
        Route::post('cashier-shifts/{id}/close', [CashierController::class, 'close'])->middleware('perm:cashier.close');
        Route::post('cash-movements', [CashierController::class, 'movement'])->middleware(['perm:cashier.operate', 'idempotent:cash.movement']);

        // Reports
        Route::get('reports/sales/daily', [ReportController::class, 'daily'])->middleware('perm:reports.view');
        Route::get('reports/sales/products', [ReportController::class, 'products'])->middleware('perm:reports.view');

        // Devices / printing
        Route::get('devices', [DeviceController::class, 'index'])->middleware('perm:devices.manage');
        Route::post('devices', [DeviceController::class, 'store'])->middleware('perm:devices.manage');
        Route::post('devices/{id}/heartbeat', [DeviceController::class, 'heartbeat'])->middleware('perm:devices.manage|kitchen.view');
        Route::post('print-jobs', [DeviceController::class, 'createPrintJob'])->middleware('perm:print.create');
        Route::get('print-jobs', [DeviceController::class, 'printJobs'])->middleware('perm:devices.manage');
        Route::post('print-jobs/{id}/result', [DeviceController::class, 'reportPrintJob'])->middleware('perm:devices.manage');
        Route::post('print-jobs/{id}/retry', [DeviceController::class, 'retryPrintJob'])->middleware('perm:print.create');
    });
};

Route::prefix('v1')->group(function () use ($tenantRoutes) {

    // ---------- Public ----------
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    // {account} = payment_accounts.account_key (omise/easyslip ต่อ tenant); mock ไม่มี account
    Route::post('webhooks/payments/{provider}/{account?}', [PaymentController::class, 'webhook'])
        ->where(['provider' => '[a-z0-9_]+', 'account' => 'pa_[a-z0-9]{32}'])->middleware('throttle:webhooks')
        ->name('webhooks.payments');

    Route::middleware('auth:sanctum')->group(function () use ($tenantRoutes) {

        // ---------- Session / Identity ----------
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('tenants', [TenantController::class, 'index']);

        // ---------- SaaS Admin ----------
        // ปิดไว้: ใช้ Filament panel (/admin, บังคับ MFA) แทน — ดูเหตุผลใน routes/api-admin.php
        // require __DIR__.'/api-admin.php';

        // ---------- Tenant-level ----------
        Route::prefix('t/{tenant}')->middleware('tenant')->group($tenantRoutes);
        Route::middleware('tenant')->group($tenantRoutes);
    });
});
