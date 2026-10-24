<?php

namespace App\Modules\Cashier\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashier\Models\CashierShift;
use App\Modules\Cashier\Models\CashMovement;
use App\Modules\Tenancy\Models\Store;
use App\Support\Errors\ApiException;
use App\Support\Money\Satang;
use App\Support\Outbox\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Policy: เปิดได้ 1 กะต่อ store (partial unique index cashier_shifts_one_open_per_store)
 */
class CashierService
{
    private const INFLOW = ['cash_in', 'cash_sale'];

    private const OUTFLOW = ['cash_out', 'refund'];

    public function __construct(private AuditLogger $audit) {}

    public function open(Store $store, string $userId, int $openingFloat): CashierShift
    {
        try {
            return DB::transaction(function () use ($store, $userId, $openingFloat) {
                $shift = CashierShift::create([
                    'tenant_id' => $store->tenant_id,
                    'store_id' => $store->id,
                    'cashier_user_id' => $userId,
                    'opening_float_satang' => $openingFloat,
                    'status' => 'open',
                    'opened_at' => now(),
                ]);
                $this->audit->log('cashier.shift_opened', $shift, ['opening_float_satang' => $openingFloat]);

                return $shift;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ApiException::conflict('shift_already_open', 'สาขานี้มีกะที่เปิดอยู่แล้ว');
            }
            throw $e;
        }
    }

    public function current(Store $store): ?CashierShift
    {
        return CashierShift::query()->where('store_id', $store->id)->where('status', 'open')->first();
    }

    /** lock กะที่เปิดอยู่ของ store (ใช้ใน transaction ของ payment/refund/cash movement) */
    public function lockOpenShift(Store|string $store): CashierShift
    {
        $storeId = $store instanceof Store ? $store->id : $store;
        $shift = CashierShift::query()->where('store_id', $storeId)->where('status', 'open')->lockForUpdate()->first();

        return $shift ?? throw ApiException::conflict('shift_not_open', 'ยังไม่ได้เปิดกะแคชเชียร์');
    }

    public function recordMovement(CashierShift $shift, string $type, int $amount, ?string $reason, ?string $actorId, ?string $refType = null, ?string $refId = null): CashMovement
    {
        return CashMovement::create([
            'tenant_id' => $shift->tenant_id,
            'store_id' => $shift->store_id,
            'shift_id' => $shift->id,
            'movement_type' => $type,
            'amount_satang' => $amount,
            'reason' => $reason,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    /** เงินเข้า/ออกลิ้นชักแบบ manual */
    public function manualMovement(Store $store, string $type, int $amount, string $reason, string $actorId): CashMovement
    {
        return DB::transaction(function () use ($store, $type, $amount, $reason, $actorId) {
            $shift = $this->lockOpenShift($store);
            $m = $this->recordMovement($shift, $type, $amount, $reason, $actorId);
            $this->audit->log('cashier.'.$type, $m, ['amount_satang' => $amount, 'reason' => $reason]);

            return $m;
        });
    }

    /**
     * ปิดกะ: lock row → คำนวณยอดคาดหวังจาก movements → บันทึกยอดนับจริงและ variance (spec §4.5)
     */
    public function close(CashierShift $shift, int $counted, ?string $note, string $actorId): CashierShift
    {
        return DB::transaction(function () use ($shift, $counted, $note, $actorId) {
            $shift = CashierShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($shift->status !== 'open') {
                throw ApiException::conflict('shift_not_open', 'กะนี้ถูกปิดไปแล้ว');
            }
            $expected = Satang::money($this->expectedCash($shift));
            $variance = Satang::money($counted)->subtract($expected);
            $shift->fill([
                'expected_cash_satang' => Satang::of($expected),
                'counted_cash_satang' => $counted,
                'variance_satang' => Satang::of($variance),
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actorId,
                'close_note' => $note,
            ])->save();

            $this->audit->log('cashier.shift_closed', $shift, [
                'expected' => Satang::of($expected), 'counted' => $counted, 'variance' => Satang::of($variance),
            ]);
            Outbox::record($shift->tenant_id, 'cashier_shift', $shift->id, 'cashier.shift_closed', ['store_id' => $shift->store_id]);

            return $shift;
        });
    }

    public function expectedCash(CashierShift $shift): int
    {
        $sums = $this->totalsByType($shift);
        $in = Satang::sum(array_intersect_key($sums, array_flip(self::INFLOW)));
        $out = Satang::sum(array_intersect_key($sums, array_flip(self::OUTFLOW)));
        $expected = Satang::money($shift->opening_float_satang)
            ->add($in)
            ->subtract($out)
            ->add(Satang::money($sums['adjustment'] ?? 0));

        return Satang::of($expected);
    }

    /** @return array<string,int> */
    public function totalsByType(CashierShift $shift): array
    {
        return CashMovement::query()->where('shift_id', $shift->id)
            ->selectRaw('movement_type, SUM(amount_satang) AS total')
            ->groupBy('movement_type')->pluck('total', 'movement_type')
            ->map(fn ($v) => (int) $v)->all();
    }
}
