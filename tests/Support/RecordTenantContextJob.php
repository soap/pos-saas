<?php

namespace Tests\Support;

use App\Modules\Catalog\Models\Product;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** job ทดสอบ: จด context ที่เห็นตอนรัน — ถ้าส่ง product มา จะถูกดึงกลับด้วย SerializesModels ใต้ global scope */
class RecordTenantContextJob implements ShouldQueue
{
    use Queueable;

    /** @var list<array{tenant_id: ?string, store_id: ?string, user_id: ?string, product_name: ?string}> */
    public static array $seen = [];

    public function __construct(public ?Product $product = null) {}

    public function handle(TenantContext $ctx): void
    {
        self::$seen[] = [
            'tenant_id' => $ctx->tenantId(),
            'store_id' => $ctx->storeId(),
            'user_id' => $ctx->user()?->id,
            'product_name' => $this->product?->name,
        ];
    }
}
