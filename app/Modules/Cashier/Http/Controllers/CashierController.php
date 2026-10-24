<?php

namespace App\Modules\Cashier\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cashier\Http\Resources\CashierShiftResource;
use App\Modules\Cashier\Models\CashierShift;
use App\Modules\Cashier\Services\CashierService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierController extends Controller
{
    public function __construct(private TenantContext $ctx, private CashierService $cashier) {}

    public function current(): JsonResponse
    {
        $shift = $this->cashier->current($this->ctx->store());

        return response()->json(['data' => $shift ? new CashierShiftResource($shift) : null]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => CashierShiftResource::collection(
            CashierShift::query()->orderByDesc('opened_at')->limit(30)->get()
        )]);
    }

    public function show(string $id): CashierShiftResource
    {
        return new CashierShiftResource(CashierShift::query()->findOrFail($id));
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['opening_float_satang' => ['required', 'integer', 'min:0']]);
        $shift = $this->cashier->open($this->ctx->store(), $request->user()->id, $data['opening_float_satang']);

        return (new CashierShiftResource($shift))->response()->setStatusCode(201);
    }

    public function close(Request $request, string $id): CashierShiftResource
    {
        $data = $request->validate([
            'counted_cash_satang' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $shift = $this->cashier->close(CashierShift::query()->findOrFail($id), $data['counted_cash_satang'], $data['note'] ?? null, $request->user()->id);

        return new CashierShiftResource($shift);
    }

    /** POST /cash-movements — เงินเข้า/ออกลิ้นชัก (ไม่ใช่การขาย) */
    public function movement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'movement_type' => ['required', 'in:cash_in,cash_out'],
            'amount_satang' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $m = $this->cashier->manualMovement($this->ctx->store(), $data['movement_type'], $data['amount_satang'], $data['reason'], $request->user()->id);

        return response()->json(['data' => $m], 201);
    }
}
