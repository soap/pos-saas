<?php

use App\Modules\Billing\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SaaS Admin API (/api/v1/admin) — ปิดไว้ ยังไม่ถูกโหลด
|--------------------------------------------------------------------------
| ตอนนี้ใช้ Filament panel (/admin) ที่บังคับ MFA แทน เพราะ login ผ่าน API
| (POST /api/v1/auth/login) ไม่มี MFA → token ของ platform admin จะข้าม MFA ได้
|
| จะเปิดใช้: ทำ MFA ฝั่ง API ก่อน แล้ว require ไฟล์นี้ภายในกลุ่ม auth:sanctum ของ routes/api.php
|   require __DIR__.'/api-admin.php';
*/

Route::prefix('admin')->middleware('platform.admin')->group(function () {
    Route::get('plans', [AdminController::class, 'plans']);
    Route::post('plans', [AdminController::class, 'createPlan']);
    Route::get('tenants', [AdminController::class, 'tenants']);
    Route::post('tenants', [AdminController::class, 'createTenant']);
    Route::post('subscriptions', [AdminController::class, 'subscribe']);
    Route::get('tenants/{id}/usage', [AdminController::class, 'usage']);
});
