<?php

namespace App\Support\Idempotency;

use App\Support\Errors\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency สำหรับคำสั่งที่อาจถูกส่งซ้ำ (spec §6 ข้อ 5)
 * ใช้: ->middleware('idempotent:orders.create')  + header "Idempotency-Key"
 *
 * - key ใหม่            → ประมวลผล แล้วเก็บ response (เฉพาะ 2xx)
 * - key เดิม + body เดิม → ส่ง response เดิมกลับ (header Idempotent-Replayed: true)
 * - key เดิม + body ต่าง → 422 idempotency_key_reused
 * - key เดิมกำลังประมวลผล → 409 idempotency_in_progress
 */
class Idempotent
{
    public function __construct(private TenantContext $ctx) {}

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9\-_:.]{8,128}$/', $key)) {
            throw ApiException::badRequest('idempotency_key_required', 'ต้องส่ง header Idempotency-Key (8-128 ตัวอักษร)');
        }

        $tenantId = $this->ctx->tenant()->id;
        $userId = $request->user()->id;
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($this->canonical($request->all())));

        $scope = [
            'tenant_id' => $tenantId,
            'actor_user_id' => $userId,
            'operation' => $operation,
            'idempotency_key' => $key,
        ];

        // ลบ key ที่หมดอายุใน scope นี้ก่อน
        DB::table('idempotency_keys')->where($scope)->where('expires_at', '<', now())->delete();

        $inserted = DB::table('idempotency_keys')->insertOrIgnore($scope + [
            'id' => (string) Str::uuid(),
            'store_id' => $this->ctx->storeId(),
            'request_hash' => $hash,
            'response_status' => null,
            'response_body' => null,
            'expires_at' => now()->addHours((int) config('pos.idempotency_ttl_hours', 24)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            $row = DB::table('idempotency_keys')->where($scope)->first();
            if (! $row) {
                throw ApiException::conflict('idempotency_in_progress', 'กรุณาลองใหม่อีกครั้ง');
            }
            if (! hash_equals($row->request_hash, $hash)) {
                throw ApiException::unprocessable('idempotency_key_reused', 'Idempotency-Key นี้ถูกใช้กับข้อมูลอื่นแล้ว');
            }
            if ($row->response_status === null) {
                throw ApiException::conflict('idempotency_in_progress', 'คำสั่งเดียวกันกำลังประมวลผลอยู่');
            }

            return new JsonResponse(json_decode($row->response_body, true), (int) $row->response_status, [
                'Idempotent-Replayed' => 'true',
            ]);
        }

        $response = $next($request);
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            DB::table('idempotency_keys')->where($scope)->update([
                'response_status' => $status,
                'response_body' => $response->getContent(),
                'updated_at' => now(),
            ]);
        } else {
            // error → ปล่อย key ให้ retry ได้หลังแก้ไขปัญหา
            DB::table('idempotency_keys')->where($scope)->delete();
        }

        return $response;
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($v) => $this->canonical($v), $value);
        }

        return $value;
    }
}
