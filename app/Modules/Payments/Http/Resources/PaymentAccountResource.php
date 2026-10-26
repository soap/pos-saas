<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\PaymentAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentAccount — ไม่ส่ง credentials ออกไปเด็ดขาด บอกเพียงว่ามีช่องใดตั้งค่าแล้ว */
class PaymentAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_code' => $this->provider_code,
            'name' => $this->name,
            'status' => $this->status,
            'settings' => $this->settings,
            'configured_credentials' => array_keys(array_filter($this->credentials ?? [])),
            'webhook_url' => $this->webhookUrl(),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
