<?php

namespace App\Support\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Errors\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * หาว่า request นี้ "เลือก" tenant ไหน จาก 3 แหล่ง (เรียงตามลำดับ):
 *   1. path    /api/v1/t/{slug}/...
 *   2. subdomain  {slug}.<pos.tenancy.subdomain_base>   (เปิดเมื่อตั้งค่า base เท่านั้น)
 *   3. header  X-Tenant-Id: <uuid>
 *
 * ถ้าส่งมาหลายแหล่งต้องชี้ tenant เดียวกัน ไม่งั้นตอบ 400 (กัน client สับสน/ถูกหลอกให้ทำผิด tenant)
 * ผลลัพธ์เป็นแค่การเลือก — สิทธิ์ยังต้องตรวจ membership ใน ResolveTenant
 */
class TenantResolver
{
    /** @return string|null tenant id, หรือ null ถ้าไม่พบ tenant ตาม slug ที่ระบุ */
    public function resolve(Request $request): ?string
    {
        $candidates = array_filter([
            'path' => $this->fromPath($request),
            'subdomain' => $this->fromSubdomain($request),
            'header' => $this->fromHeader($request),
        ], fn ($c) => $c !== null);

        if ($candidates === []) {
            throw ApiException::badRequest('tenant_context_missing', 'ต้องระบุ tenant ผ่าน path /t/{slug}, subdomain หรือ X-Tenant-Id');
        }

        // slug → id (slug ที่ไม่มีอยู่ = null ซึ่งจะถูกปฏิเสธแบบเดียวกับไม่ใช่สมาชิก)
        $ids = array_map(fn (array $c) => $c['by'] === 'id' ? $c['value'] : $this->idForSlug($c['value']), $candidates);

        if (count(array_unique($ids, SORT_REGULAR)) > 1) {
            throw ApiException::badRequest('tenant_context_conflict', 'tenant ที่ระบุจาก '.implode(', ', array_keys($candidates)).' ไม่ตรงกัน');
        }

        return reset($ids);
    }

    /** @return array{by:string,value:string}|null */
    private function fromPath(Request $request): ?array
    {
        $slug = $request->route()?->parameter('tenant');

        return is_string($slug) ? ['by' => 'slug', 'value' => $slug] : null;
    }

    /** @return array{by:string,value:string}|null */
    private function fromSubdomain(Request $request): ?array
    {
        $base = strtolower((string) config('pos.tenancy.subdomain_base'));
        $host = strtolower($request->getHost());
        if ($base === '' || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $label = substr($host, 0, -strlen('.'.$base));
        // รับเฉพาะ 1 ระดับ และข้าม subdomain ของระบบ (app., api., www. ...)
        if (! preg_match('/^'.Tenant::SLUG_PATTERN.'$/', $label) || in_array($label, config('pos.tenancy.reserved_slugs'), true)) {
            return null;
        }

        return ['by' => 'slug', 'value' => $label];
    }

    /** @return array{by:string,value:string}|null */
    private function fromHeader(Request $request): ?array
    {
        if (! $request->headers->has('X-Tenant-Id')) {
            return null;
        }
        $id = (string) $request->header('X-Tenant-Id');
        if (! Str::isUuid($id)) {
            throw ApiException::badRequest('tenant_context_missing', 'X-Tenant-Id ต้องเป็น UUID');
        }

        return ['by' => 'id', 'value' => strtolower($id)];
    }

    private function idForSlug(string $slug): ?string
    {
        return Tenant::query()->where('slug', $slug)->value('id');
    }
}
