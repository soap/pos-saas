<?php

namespace App\Modules\Cashier\Http\Resources;

use App\Modules\Cashier\Models\CashierShift;
use App\Modules\Cashier\Services\CashierService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CashierShift */
class CashierShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $svc = app(CashierService::class);

        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'cashier_user_id' => $this->cashier_user_id,
            'status' => $this->status,
            'opening_float_satang' => $this->opening_float_satang,
            'totals_by_type' => $svc->totalsByType($this->resource),
            'expected_cash_satang' => $this->status === 'open' ? $svc->expectedCash($this->resource) : $this->expected_cash_satang,
            'counted_cash_satang' => $this->counted_cash_satang,
            'variance_satang' => $this->variance_satang,
            'opened_at' => $this->opened_at?->toAtomString(),
            'closed_at' => $this->closed_at?->toAtomString(),
            'closed_by' => $this->closed_by,
            'close_note' => $this->close_note,
        ];
    }
}
