<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Route;

/**
 * routes/api-admin.php ยังไม่ถูกโหลดใน routes/api.php — test ที่ครอบคลุม admin API
 * mount ไฟล์นี้ในตำแหน่งเดียวกับที่จะ require (api → v1 → auth:sanctum) เพื่อให้เปิดกลับมาได้อย่างมั่นใจ
 */
trait MountsAdminApiRoutes
{
    protected function mountAdminApiRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(base_path('routes/api-admin.php'));
    }
}
