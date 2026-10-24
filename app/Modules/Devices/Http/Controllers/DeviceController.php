<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Devices\Models\DeviceEvent;
use App\Modules\Devices\Models\PrintJob;
use App\Modules\Devices\Models\StoreDevice;
use App\Modules\Devices\Services\PrintService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ทะเบียนอุปกรณ์ + print job queue สำหรับ Local Print Bridge (ภาคผนวก C)
 */
class DeviceController extends Controller
{
    public function __construct(private TenantContext $ctx, private PrintService $print, private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => StoreDevice::query()->orderBy('device_code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->ctx->store();
        $data = $request->validate([
            'device_code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('store_devices')->where('store_id', $store->id)],
            'name' => ['required', 'string', 'max:255'],
            'device_type' => ['required', 'in:pos_terminal,receipt_printer,kitchen_printer,kitchen_display,cash_drawer,qr_display,barcode_scanner'],
            'connection_type' => ['required', 'in:browser,network_escpos,bridge,vendor_cloud,usb,bluetooth'],
            'config' => ['nullable', 'array'],
            'config.host' => ['nullable', 'ip'],
            'config.port' => ['nullable', 'integer', 'between:1,65535'],
            'config.paper_width_mm' => ['nullable', 'in:58,80'],
        ]);
        $device = StoreDevice::create($data + ['config' => $data['config'] ?? [], 'status' => 'active']);
        $this->audit->log('device.registered', $device, $data);

        return response()->json(['data' => $device], 201);
    }

    /** POST /devices/{id}/heartbeat — bridge/จอครัวรายงานสถานะ */
    public function heartbeat(Request $request, string $id): JsonResponse
    {
        $device = StoreDevice::query()->findOrFail($id);
        $data = $request->validate([
            'event_type' => ['nullable', 'in:heartbeat,online,offline,printer_error,paper_out'],
            'detail' => ['nullable', 'string', 'max:255'],
        ]);
        $device->update(['last_seen_at' => now()]);
        $type = $data['event_type'] ?? 'heartbeat';
        if ($type !== 'heartbeat') {
            DeviceEvent::create([
                'device_id' => $device->id, 'event_type' => $type,
                'payload' => ['detail' => $data['detail'] ?? null], 'occurred_at' => now(),
            ]);
        }

        return response()->json(['data' => ['device_id' => $device->id, 'last_seen_at' => $device->last_seen_at->toAtomString()]]);
    }

    // ---------------- Print jobs ----------------

    /** POST /print-jobs {document_type, source_id, reprint?, printer_device_id?} */
    public function createPrintJob(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'in:receipt,kitchen_ticket'],
            'source_id' => ['required', 'uuid'],
            'reprint' => ['nullable', 'boolean'],
            'printer_device_id' => ['nullable', 'uuid'],
        ]);
        $result = $this->print->request(
            $this->ctx->store(), $data['document_type'], $data['source_id'], (bool) ($data['reprint'] ?? false),
            $data['printer_device_id'] ?? null, $request->user()->id,
        );

        return response()->json(['data' => $result['job']], $result['created'] ? 201 : 200);
    }

    /** GET /print-jobs?status=pending — bridge ดึงงานที่ต้องพิมพ์ */
    public function printJobs(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,printing,printed,failed,cancelled']]);
        $jobs = PrintJob::query()->where('status', $request->query('status', 'pending'))
            ->orderBy('requested_at')->limit(50)->get();

        return response()->json(['data' => $jobs]);
    }

    /** POST /print-jobs/{id}/result {status: printing|printed|failed, error?} */
    public function reportPrintJob(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:printing,printed,failed'],
            'error' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->print->report(PrintJob::query()->findOrFail($id), $data['status'], $data['error'] ?? null)]);
    }

    public function retryPrintJob(string $id): JsonResponse
    {
        return response()->json(['data' => $this->print->retry(PrintJob::query()->findOrFail($id))]);
    }
}
