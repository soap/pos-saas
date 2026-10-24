<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * รายงานยอดขายอิง business_date ตาม timezone ของสาขา (spec §8) — ไม่ใช้ UTC date ตรง ๆ
 * ยอดขาย = ออเดอร์สถานะ completed
 */
class SalesReport
{
    public function daily(Store $store, string $from, string $to): array
    {
        $orders = DB::table('orders')
            ->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw("business_date,
                COUNT(*) FILTER (WHERE status = 'completed') AS completed_orders,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_orders,
                COALESCE(SUM(subtotal_satang) FILTER (WHERE status = 'completed'),0) AS subtotal_satang,
                COALESCE(SUM(discount_satang) FILTER (WHERE status = 'completed'),0) AS discount_satang,
                COALESCE(SUM(tax_satang) FILTER (WHERE status = 'completed'),0) AS tax_satang,
                COALESCE(SUM(total_satang) FILTER (WHERE status = 'completed'),0) AS total_satang")
            ->groupBy('business_date')->orderBy('business_date')->get()->keyBy('business_date');

        $payments = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.tenant_id', $store->tenant_id)->where('p.store_id', $store->id)
            ->whereBetween('o.business_date', [$from, $to])
            ->whereIn('p.status', ['succeeded', 'partially_refunded', 'refunded'])
            ->selectRaw('o.business_date, p.method, SUM(p.amount_satang) AS paid, SUM(p.refunded_satang) AS refunded')
            ->groupBy('o.business_date', 'p.method')->get();

        $rows = [];
        foreach ($orders as $date => $o) {
            $rows[$date] = [
                'business_date' => $date,
                'completed_orders' => (int) $o->completed_orders,
                'cancelled_orders' => (int) $o->cancelled_orders,
                'subtotal_satang' => (int) $o->subtotal_satang,
                'discount_satang' => (int) $o->discount_satang,
                'tax_satang' => (int) $o->tax_satang,
                'total_satang' => (int) $o->total_satang,
                'payments' => ['cash' => 0, 'qr' => 0],
                'refunds_satang' => 0,
            ];
        }
        foreach ($payments as $p) {
            $rows[$p->business_date] ??= [
                'business_date' => $p->business_date, 'completed_orders' => 0, 'cancelled_orders' => 0,
                'subtotal_satang' => 0, 'discount_satang' => 0, 'tax_satang' => 0, 'total_satang' => 0,
                'payments' => ['cash' => 0, 'qr' => 0], 'refunds_satang' => 0,
            ];
            $rows[$p->business_date]['payments'][$p->method] = (int) $p->paid;
            $rows[$p->business_date]['refunds_satang'] += (int) $p->refunded;
        }
        ksort($rows);

        return [
            'store_id' => $store->id,
            'timezone' => $store->timezone,
            'from' => $from,
            'to' => $to,
            'days' => array_values($rows),
        ];
    }

    public function products(Store $store, string $from, string $to): array
    {
        $rows = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.tenant_id', $store->tenant_id)->where('o.store_id', $store->id)
            ->whereBetween('o.business_date', [$from, $to])
            ->where('o.status', 'completed')
            ->where('i.status', 'active')
            ->selectRaw('i.product_id, i.product_name_snapshot AS name, SUM(i.quantity) AS quantity, SUM(i.line_total_satang) AS revenue_satang')
            ->groupBy('i.product_id', 'i.product_name_snapshot')
            ->orderByDesc('revenue_satang')->get();

        return [
            'store_id' => $store->id,
            'from' => $from,
            'to' => $to,
            'products' => $rows->map(fn ($r) => [
                'product_id' => $r->product_id,
                'name' => $r->name,
                'quantity' => (int) $r->quantity,
                'revenue_satang' => (int) $r->revenue_satang,
            ])->values(),
        ];
    }
}
