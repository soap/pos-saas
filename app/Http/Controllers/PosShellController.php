<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * หน้า shell ของ Vue SPA หน้าร้าน — บอก SPA ว่า URL นี้ "ปักหมุด" ร้านไหน
 *
 *   /pos/t/{slug}/...       ปักหมุดด้วย path
 *   {slug}.<base>/pos/...   ปักหมุดด้วย subdomain
 *   /pos/...                ไม่ปักหมุด (เลือกร้านหลัง login)
 *
 * ไม่ค้นหา tenant ในฐานข้อมูล เพื่อไม่ให้ใช้หน้านี้เดาว่ามีร้านใดอยู่บ้าง — สิทธิ์ตรวจที่ API หลัง login
 */
class PosShellController extends Controller
{
    public function __invoke(Request $request, TenantResolver $resolver): View
    {
        // อ่านจากชื่อ parameter — ถ้ารับเป็น argument, route /pos/{any?} จะยัด {any} ใส่ตามตำแหน่ง
        $tenant = $request->route('tenant');
        // /pos/t/... สงวนไว้สำหรับร้าน — slug ผิดรูปแบบไม่ผ่าน route pattern แล้วตกมาที่ /pos/{any?}
        abort_if($tenant === null && preg_match('#^t(/|$)#', (string) $request->route('any')) === 1, 404);
        $subdomainSlug = $resolver->subdomainSlug($request);

        if ($tenant !== null) {
            abort_unless($resolver->isUsableSlug($tenant), 404);
            // path กับ subdomain ชี้คนละร้าน → ไม่เดาว่าผู้ใช้ตั้งใจร้านไหน
            abort_if($subdomainSlug !== null && $subdomainSlug !== $tenant, 400, 'tenant ใน path และ subdomain ไม่ตรงกัน');
        }

        return view('pos', [
            'posConfig' => [
                'tenant' => match (true) {
                    $tenant !== null => ['slug' => $tenant, 'via' => 'path'],
                    $subdomainSlug !== null => ['slug' => $subdomainSlug, 'via' => 'subdomain'],
                    default => null,
                },
                // ให้หน้าหลังร้านแสดงลิงก์ประจำร้านแบบ subdomain ได้ (ว่าง = ไม่ได้เปิดใช้)
                'subdomain_base' => config('pos.tenancy.subdomain_base') ?: null,
                // key ของ Reverb เป็นค่าสาธารณะ (เหมือน Pusher app key) — secret อยู่ฝั่ง server เท่านั้น
                'realtime' => config('broadcasting.default') === 'reverb' ? [
                    'key' => config('broadcasting.connections.reverb.key'),
                    'host' => config('pos.realtime.host'),
                    'port' => config('pos.realtime.port'),
                    'scheme' => config('pos.realtime.scheme'),
                ] : null,
            ],
        ]);
    }
}
