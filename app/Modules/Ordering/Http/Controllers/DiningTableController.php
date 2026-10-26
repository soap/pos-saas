<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Ordering\Http\Resources\DiningTableResource;
use App\Modules\Ordering\Models\DiningTable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** โต๊ะของสาขา (ต้องมี X-Store-Id) — global scope จำกัดเฉพาะสาขาปัจจุบัน */
class DiningTableController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** GET /tables?include_inactive=1 — ผังโต๊ะพร้อมออเดอร์ที่เปิดอยู่ */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tables = DiningTable::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->with('openOrder')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return DiningTableResource::collection($tables);
    }

    public function store(Request $request): JsonResponse
    {
        $table = DiningTable::create($this->validated($request, null));
        $this->audit->log('dining_table.created', $table, $table->only(['name', 'zone', 'seats']));

        return (new DiningTableResource($table))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): DiningTableResource
    {
        $table = DiningTable::query()->findOrFail($id);
        $data = $this->validated($request, $table);
        $table->update($data);
        $this->audit->log('dining_table.updated', $table, $data);

        return new DiningTableResource($table);
    }

    /**
     * @return array{name?: string, zone?: ?string, seats?: ?int, sort_order?: int, is_active?: bool}
     */
    private function validated(Request $request, ?DiningTable $table): array
    {
        return $request->validate([
            'name' => [$table ? 'sometimes' : 'required', 'string', 'max:32', $this->uniqueName($table)],
            'zone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'seats' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** ชื่อซ้ำในสาขาเดียวกันไม่ได้ (ไม่สนตัวพิมพ์) — ตรงกับ unique index ใน DB */
    private function uniqueName(?DiningTable $table): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($table): void {
            $exists = DiningTable::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                ->when($table, fn ($q) => $q->whereKeyNot($table->id))
                ->exists();
            if ($exists) {
                $fail('มีโต๊ะชื่อนี้ในสาขาแล้ว');
            }
        };
    }
}
