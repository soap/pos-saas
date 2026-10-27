<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Services\BillingService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ค่าแพ็กเกจของร้าน — route กลุ่มนี้ผ่าน middleware tenant:billing (ไม่ตรวจ subscription)
 * ร้านที่ถูกระงับจึงยังเข้ามาดูยอดและจ่ายได้
 */
class BillingController extends Controller
{
    public function __construct(private BillingService $billing, private TenantContext $ctx) {}

    /** GET /billing */
    public function show(): JsonResponse
    {
        $summary = $this->billing->summary($this->ctx->tenant()->id);
        $sub = $summary['subscription'];

        return response()->json(['data' => [
            'subscription' => $sub ? [
                'status' => $sub->status,
                'plan' => $sub->plan->only(['code', 'name', 'price_satang', 'billing_interval']),
                'trial_ends_at' => $sub->trial_ends_at?->toAtomString(),
                'paid_through' => $summary['paid_through']?->toAtomString(),
                'grace_ends_at' => $summary['grace_ends_at']?->toAtomString(),
            ] : null,
            'usable' => $summary['usable'],
            'invoices' => $summary['invoices']->map(fn (SubscriptionPayment $invoice) => $this->invoice($invoice))->all(),
        ]]);
    }

    /** POST /billing/invoices/{id}/pay — คืน QR PromptPay (ใช้ QR เดิมถ้ายังไม่หมดอายุ) */
    public function pay(string $id): JsonResponse
    {
        return response()->json(['data' => $this->invoice($this->billing->pay(SubscriptionPayment::query()->findOrFail($id)))]);
    }

    /** GET /billing/invoices/{id}?refresh=1 — refresh=1 ถาม Omise ตรง ๆ เมื่อ webhook ช้า */
    public function invoiceStatus(Request $request, string $id): JsonResponse
    {
        $invoice = SubscriptionPayment::query()->findOrFail($id);
        if ($request->boolean('refresh')) {
            $invoice = $this->billing->refresh($invoice);
        }

        return response()->json(['data' => $this->invoice($invoice)]);
    }

    /** POST /webhooks/billing/omise — ไม่ต้อง login; ตรวจ signature ด้วย webhook secret ของบัญชีแพลตฟอร์ม */
    public function webhook(Request $request): JsonResponse
    {
        return response()->json(['result' => $this->billing->handleWebhook($request->headers->all(), $request->getContent())]);
    }

    /** @return array<string, mixed> */
    private function invoice(SubscriptionPayment $invoice): array
    {
        $hasLiveCharge = $invoice->status === 'pending' && $invoice->hasLiveCharge();

        return [
            'id' => $invoice->id,
            'status' => $invoice->status,
            'amount_satang' => $invoice->amount_satang,
            'billing_period_start' => $invoice->billing_period_start->toAtomString(),
            'billing_period_end' => $invoice->billing_period_end->toAtomString(),
            'paid_at' => $invoice->paid_at?->toAtomString(),
            'qr_image_url' => $hasLiveCharge ? $invoice->qr_image_url : null,
            'qr_expires_at' => $hasLiveCharge ? $invoice->charge_expires_at->toAtomString() : null,
        ];
    }
}
