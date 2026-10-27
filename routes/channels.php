<?php

use App\Modules\Identity\Models\User;
use App\Support\Realtime\StoreActivity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Broadcast;

/*
| ขอสิทธิ์ผ่าน POST /api/v1[/t/{slug}]/broadcasting/auth (routes/api.php) ซึ่งผ่าน middleware tenant + store แล้ว
| → TenantContext ตรวจ membership ของสาขาให้แล้ว ที่นี่เหลือแค่เทียบว่าขอ channel ของสาขาเดียวกับ X-Store-Id
*/
Broadcast::channel(StoreActivity::channelName('{storeId}'), function (User $user, string $storeId): bool {
    return app(TenantContext::class)->storeId() === $storeId;
});
