<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * บันทึก audit (spec §8) — ห้ามเก็บ password, token, payment secret
 */
class AuditLogger
{
    private const REDACT = ['password', 'password_confirmation', 'token', 'secret', 'signature', 'authorization'];

    public function __construct(private TenantContext $ctx) {}

    public function log(string $action, ?Model $subject = null, array $metadata = [], ?string $actorId = null, ?string $tenantId = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'tenant_id' => $tenantId ?? $this->ctx->tenantId(),
            'store_id' => $this->ctx->storeId(),
            'actor_user_id' => $actorId ?? $request?->user()?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'request_id' => $request?->attributes->get('request_id'),
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500),
            'metadata' => $this->redact($metadata),
            'created_at' => now(),
        ]);
    }

    private function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT, true)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = $this->redact($v);
            }
        }

        return Arr::except($data, []);
    }
}
