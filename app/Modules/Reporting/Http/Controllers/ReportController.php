<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\SalesReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(private TenantContext $ctx, private SalesReport $report) {}

    public function daily(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->report->daily($this->ctx->store(), $from, $to)]);
    }

    public function products(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->report->products($this->ctx->store(), $from, $to)]);
    }

    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = Carbon::now($this->ctx->store()->timezone)->toDateString();
        $from = $request->query('from', $today);
        $to = $request->query('to', $from);
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92) {
            abort(422, 'ช่วงวันที่ต้องไม่เกิน 92 วัน');
        }

        return [$from, $to];
    }
}
