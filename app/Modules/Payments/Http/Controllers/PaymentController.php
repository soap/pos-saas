<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Http\Requests\RefundRequest;
use App\Modules\Payments\Http\Requests\StartPaymentRequest;
use App\Modules\Payments\Http\Requests\SubmitSlipRequest;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\RefundService;
use App\Modules\Payments\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    /** POST /orders/{id}/payments */
    public function store(StartPaymentRequest $request, string $orderId): JsonResponse
    {
        $data = $request->validated();
        $result = $this->payments->start(
            Order::query()->findOrFail($orderId),
            $data['method'],
            $data['amount_satang'] ?? null,
            $data['tendered_satang'] ?? null,
            $request->user()->id,
        );

        return response()->json([
            'data' => new PaymentResource($result['payment']),
            'qr' => $result['qr'],
        ], 201);
    }

    /** GET /payments/{id}?refresh=1 — refresh=1 จะถามสถานะจาก provider (fallback เมื่อ webhook ช้า) */
    public function show(Request $request, string $id): PaymentResource
    {
        $payment = Payment::query()->findOrFail($id);
        if ($request->boolean('refresh')) {
            $payment = $this->payments->refresh($payment);
        }

        return new PaymentResource($payment->load(['refunds', 'attempts']));
    }

    /** POST /payments/{id}/slip — easyslip: ส่ง QR บนสลิปให้ตรวจ ผลมาทาง webhook แล้ว poll GET /payments/{id} */
    public function submitSlip(SubmitSlipRequest $request, string $id): JsonResponse
    {
        $payment = $this->payments->submitSlip(Payment::query()->findOrFail($id), $request->validated('slip_payload'), $request->user()->id);

        return (new PaymentResource($payment->load('attempts')))->response()->setStatusCode(202);
    }

    public function cancel(Request $request, string $id): PaymentResource
    {
        return new PaymentResource($this->payments->cancel(Payment::query()->findOrFail($id), $request->user()->id));
    }

    /** POST /payments/{id}/refunds */
    public function refund(RefundRequest $request, string $id, RefundService $refunds): JsonResponse
    {
        $data = $request->validated();
        $refund = $refunds->refund(
            Payment::query()->findOrFail($id), $data['amount_satang'], $data['reason'], $request->user()->id,
            $data['refund_method'] ?? null, $data['transfer_reference'] ?? null,
        );

        return response()->json([
            'data' => [
                'id' => $refund->id,
                'payment_id' => $refund->payment_id,
                'amount_satang' => $refund->amount_satang,
                'status' => $refund->status,
                'method' => $refund->method,
                'reason' => $refund->reason,
                'succeeded_at' => $refund->succeeded_at?->toAtomString(),
            ],
            'payment' => new PaymentResource(Payment::query()->with('refunds')->findOrFail($id)),
        ], 201);
    }

    /** POST /webhooks/payments/{provider}/{account?} — ไม่ต้อง login; ตรวจ signature จาก raw body ด้วย secret ของ account */
    public function webhook(Request $request, WebhookService $webhooks, string $provider, ?string $account = null): JsonResponse
    {
        $result = $webhooks->handle($provider, $request->headers->all(), $request->getContent(), $account);

        return response()->json(['result' => $result]);
    }
}
