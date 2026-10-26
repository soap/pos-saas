<?php

namespace App\Modules\Payments\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * บัญชีรับชำระเงิน QR ของ tenant (spec ภาคผนวก B7)
 * credentials เข้ารหัสด้วย APP_KEY และไม่ถูก serialize ออกไป — ใช้ได้เฉพาะใน PaymentProviderRegistry
 */
class PaymentAccount extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** URL ที่ต้องนำไปตั้งใน dashboard ของ provider (หรือส่งเป็น callbackUrl) */
    public function webhookUrl(): string
    {
        return route('webhooks.payments', ['provider' => $this->provider_code, 'account' => $this->account_key]);
    }
}
