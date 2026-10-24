<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Devices\Services\PrintService;
use App\Modules\Ordering\Http\Requests\AddItemsRequest;
use App\Modules\Ordering\Http\Requests\CreateOrderRequest;
use App\Modules\Ordering\Http\Resources\OrderResource;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private TenantContext $ctx, private OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string'],
        ]);
        $date = $request->query('business_date', $this->orders->businessDate($this->ctx->store()));
        $q = Order::query()->where('business_date', $date)->orderByDesc('order_number');
        if ($request->filled('status')) {
            $q->whereIn('status', explode(',', (string) $request->query('status')));
        }

        return OrderResource::collection($q->paginate(50));
    }

    public function show(string $id): OrderResource
    {
        return new OrderResource($this->loadFull($id));
    }

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($this->ctx->store(), $request->user()->id, $request->validated());

        return (new OrderResource($this->loadFull($order->id)))->response()->setStatusCode(201);
    }

    public function addItems(AddItemsRequest $request, string $id): OrderResource
    {
        $order = Order::query()->findOrFail($id);
        $this->orders->addItems($this->ctx->store(), $order, $request->user()->id, $request->validated('items'));

        return new OrderResource($this->loadFull($id));
    }

    public function updateItem(Request $request, string $id, string $item): OrderResource
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:999']]);
        $this->orders->updateItemQuantity(Order::query()->findOrFail($id), $item, $data['quantity'], $request->user()->id);

        return new OrderResource($this->loadFull($id));
    }

    public function removeItem(Request $request, string $id, string $item): OrderResource
    {
        $this->orders->removeItem(Order::query()->findOrFail($id), $item, $request->user()->id);

        return new OrderResource($this->loadFull($id));
    }

    public function discount(Request $request, string $id): OrderResource
    {
        $data = $request->validate([
            'discount_satang' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $this->orders->applyDiscount(Order::query()->findOrFail($id), $data['discount_satang'], $data['reason'] ?? null, $request->user()->id);

        return new OrderResource($this->loadFull($id));
    }

    public function cancel(Request $request, string $id): OrderResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->orders->cancel(Order::query()->findOrFail($id), $data['reason'], $request->user()->id);

        return new OrderResource($this->loadFull($id));
    }

    public function events(string $id): JsonResponse
    {
        $order = Order::query()->findOrFail($id);

        return response()->json(['data' => $order->events()->get(['id', 'event_type', 'from_status', 'to_status', 'actor_id', 'payload', 'created_at'])]);
    }

    /** GET /orders/{id}/receipt — ข้อมูลใบเสร็จสำหรับ browser print */
    public function receipt(string $id, PrintService $print): JsonResponse
    {
        $order = Order::query()->with(['items.modifiers', 'payments'])->findOrFail($id);

        return response()->json(['data' => $print->receiptPayload($order, $this->ctx->store())]);
    }

    private function loadFull(string $id): Order
    {
        return Order::query()->with(['items.modifiers', 'kitchenTickets.items', 'payments.refunds'])->findOrFail($id);
    }
}
