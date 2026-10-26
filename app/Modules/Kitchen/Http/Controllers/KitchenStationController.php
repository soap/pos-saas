<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Kitchen\Models\KitchenStation;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** station ครัวระดับ tenant (ภาคผนวก D5) — จอครัวใช้เลือกว่าจะแสดงงานของ station ไหน */
class KitchenStationController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** GET /kitchen-stations?include_inactive=1 */
    public function index(Request $request): JsonResponse
    {
        $stations = KitchenStation::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'sort_order', 'is_active']);

        return response()->json(['data' => $stations]);
    }

    public function store(Request $request): JsonResponse
    {
        $station = KitchenStation::create($this->validated($request, null));
        $this->audit->log('kitchen_station.created', $station, $station->only(['name']));

        return response()->json(['data' => $station->only(['id', 'name', 'sort_order', 'is_active'])], 201);
    }

    /** ปิดใช้งาน station แล้ว ส่งครัวครั้งถัดไปรายการของ station นี้จะไปครัวหลัก (ticket เดิมไม่ย้าย) */
    public function update(Request $request, string $id): JsonResponse
    {
        $station = KitchenStation::query()->findOrFail($id);
        $data = $this->validated($request, $station);
        $station->update($data);
        $this->audit->log('kitchen_station.updated', $station, $data);

        return response()->json(['data' => $station->only(['id', 'name', 'sort_order', 'is_active'])]);
    }

    /**
     * @return array{name?: string, sort_order?: int, is_active?: bool}
     */
    private function validated(Request $request, ?KitchenStation $station): array
    {
        return $request->validate([
            'name' => [$station ? 'sometimes' : 'required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) use ($station): void {
                $exists = KitchenStation::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($station, fn ($q) => $q->whereKeyNot($station->id))
                    ->exists();
                if ($exists) {
                    $fail('มี station ชื่อนี้แล้ว');
                }
            }],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
