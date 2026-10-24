<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Kitchen\Http\Resources\KitchenTicketResource;
use App\Modules\Kitchen\Models\KitchenTicket;
use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Ordering\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kitchen Dashboard API (ภาคผนวก D4) — polling MVP ด้วย updated_after cursor
 */
class KitchenController extends Controller
{
    public function __construct(private KitchenService $kitchen) {}

    private const STATUSES = ['queued', 'preparing', 'ready', 'served', 'cancelled'];

    private const MAX_LIMIT = 200;

    /**
     * GET /kitchen/tickets?status=queued,preparing&updated_after=<server_time จากครั้งก่อน>&limit=&cursor=
     * - ไม่ส่ง updated_after = full refresh
     * - response มี server_time ให้ใช้เป็น cursor ครั้งถัดไป (ไม่พึ่งนาฬิกา browser)
     * - แบ่งหน้าแบบ keyset ตาม (updated_at, id): ถ้า has_more=true ให้เรียกซ้ำด้วย query เดิม + cursor=next_cursor
     *   จนหมด แล้วใช้ server_time ของหน้าแรกเป็น updated_after รอบถัดไป; client เรียงการ์ดตาม sent_at เอง
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'updated_after' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:200'],
        ]);
        $statuses = $request->filled('status') ? explode(',', (string) $request->query('status')) : null;
        if ($statuses !== null && array_diff($statuses, self::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'status ต้องเป็น '.implode(',', self::STATUSES)]);
        }
        $limit = (int) $request->query('limit', self::MAX_LIMIT);

        $serverTime = now();
        $q = KitchenTicket::query()->with(['items', 'order:id,order_number,order_type,table_label'])
            ->orderBy('updated_at')->orderBy('id');

        if ($request->filled('cursor')) {
            [$cursorTime, $cursorId] = $this->decodeCursor((string) $request->query('cursor'));
            $q->whereRaw('(kitchen_tickets.updated_at, kitchen_tickets.id) > (?::timestamptz, ?::uuid)', [$cursorTime, $cursorId]);
        }

        if ($request->filled('updated_after')) {
            // delta: รวมทุกสถานะ (รวม cancelled/served) เพื่อให้ client เอาการ์ดออกได้
            // ใช้ >= เพราะ timestamp ละเอียดระดับวินาที; client ต้อง merge ด้วย id (ได้รายการซ้ำได้)
            $q->where('updated_at', '>=', Carbon::parse($request->query('updated_after')));
        } else {
            $q->whereIn('status', $statuses ?? ['queued', 'preparing', 'ready']);
        }

        $tickets = $q->limit($limit + 1)->get();
        $hasMore = $tickets->count() > $limit;
        if ($hasMore) {
            $tickets = $tickets->take($limit);
        }
        $last = $tickets->last();

        return response()->json([
            'data' => KitchenTicketResource::collection($tickets),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->encodeCursor($last->getRawOriginal('updated_at'), $last->id) : null,
            'server_time' => $serverTime->toAtomString(),
            'poll_interval_seconds' => config('pos.kitchen.poll_interval_seconds'),
            'late_threshold_minutes' => config('pos.kitchen.late_threshold_minutes'),
        ]);
    }

    public function show(string $id): KitchenTicketResource
    {
        return new KitchenTicketResource(KitchenTicket::query()->with(['items', 'order'])->findOrFail($id));
    }

    public function sendToKitchen(Request $request, string $orderId): JsonResponse
    {
        $ticket = $this->kitchen->sendToKitchen(Order::query()->findOrFail($orderId), $request->user()->id);

        return (new KitchenTicketResource($ticket->load('order')))->response()->setStatusCode(201);
    }

    public function acknowledge(Request $request, string $id): KitchenTicketResource
    {
        return $this->transition($request, $id, 'acknowledge');
    }

    public function ready(Request $request, string $id): KitchenTicketResource
    {
        return $this->transition($request, $id, 'ready');
    }

    public function served(Request $request, string $id): KitchenTicketResource
    {
        return $this->transition($request, $id, 'served');
    }

    public function cancel(Request $request, string $id): KitchenTicketResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->transition($request, $id, 'cancel', $data['reason']);
    }

    private function encodeCursor(string $updatedAt, string $id): string
    {
        return rtrim(strtr(base64_encode($updatedAt.'|'.$id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string} */
    private function decodeCursor(string $cursor): array
    {
        $parts = explode('|', (string) base64_decode(strtr($cursor, '-_', '+/'), true), 2);
        if (count($parts) !== 2 || ! Str::isUuid($parts[1]) || strtotime($parts[0]) === false) {
            throw ValidationException::withMessages(['cursor' => 'cursor ไม่ถูกต้อง']);
        }

        return $parts;
    }

    private function transition(Request $request, string $id, string $action, ?string $reason = null): KitchenTicketResource
    {
        $ticket = KitchenTicket::query()->findOrFail($id);
        $ticket = $this->kitchen->transition($ticket, $action, $request->user()->id, $reason);

        return new KitchenTicketResource($ticket->load(['items', 'order']));
    }
}
