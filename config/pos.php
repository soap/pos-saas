<?php

use App\Modules\Payments\Providers\MockQrProvider;

return [
    // ภาษี: ร้านนำร่องไม่จด VAT → tax_satang = 0 (โครงสร้าง tax_profile เผื่อไว้)
    'tax_mode' => env('POS_TAX_MODE', 'none'), // none (MVP)

    'idempotency_ttl_hours' => (int) env('POS_IDEMPOTENCY_TTL_HOURS', 24),

    // บังคับให้ tenant ต้องมี subscription สถานะ trialing/active/past_due จึงใช้ API ได้
    'enforce_subscription' => (bool) env('POS_ENFORCE_SUBSCRIPTION', true),

    'tenancy' => [
        // ระบุ tenant จาก subdomain <slug>.<base> เช่น p-prateep.pos.example.com (ว่าง = ปิด)
        'subdomain_base' => env('POS_TENANT_BASE_DOMAIN'),
        // slug ที่ห้ามใช้ เพราะชนกับ subdomain/path ของระบบ
        'reserved_slugs' => ['www', 'api', 'app', 'admin', 'auth', 'static', 'assets', 'cdn', 'mail', 'status', 'help', 'docs', 'webhooks'],
    ],

    'kitchen' => [
        'poll_interval_seconds' => (int) env('POS_KDS_POLL_SECONDS', 3),
        'late_threshold_minutes' => (int) env('POS_KDS_LATE_MINUTES', 15),
    ],

    'payments' => [
        'default_qr_provider' => env('POS_QR_PROVIDER', 'mock'),
        'providers' => [
            'mock' => [
                'class' => MockQrProvider::class,
                // secret อยู่ใน environment เท่านั้น ไม่เก็บใน DB (spec §8)
                'webhook_secret' => env('MOCK_QR_WEBHOOK_SECRET', 'change-me-mock-secret'),
                'account_key' => env('MOCK_QR_ACCOUNT_KEY', 'mock-account'),
                'qr_ttl_minutes' => 15,
                'replay_window_seconds' => 300,
            ],
        ],
    ],

    // สิทธิ์ทั้งหมดในระบบ (permission keys คงที่ในโค้ด — spec §4.1)
    'permissions' => [
        'stores.view' => 'ดูสาขา',
        'stores.manage' => 'สร้าง/แก้ไขสาขา',
        'members.manage' => 'จัดการสมาชิกและบทบาท',
        'catalog.view' => 'ดูเมนู',
        'catalog.manage' => 'จัดการเมนู ราคา ตัวเลือก',
        'orders.view' => 'ดูออเดอร์',
        'orders.create' => 'เปิด/เพิ่มรายการออเดอร์',
        'orders.discount' => 'ให้ส่วนลดออเดอร์',
        'orders.cancel' => 'ยกเลิกออเดอร์',
        'kitchen.view' => 'ดูจอครัว',
        'kitchen.update' => 'เปลี่ยนสถานะงานครัว',
        'kitchen.void' => 'ยกเลิก ticket ครัว',
        'payments.view' => 'ดูการชำระเงิน',
        'payments.create' => 'รับชำระเงิน',
        'payments.refund' => 'คืนเงิน',
        'cashier.operate' => 'เปิดกะ/บันทึกเงินเข้าออก',
        'cashier.close' => 'ปิดกะ',
        'reports.view' => 'ดูรายงาน',
        'devices.manage' => 'จัดการอุปกรณ์',
        'print.create' => 'สั่งพิมพ์',
    ],

    // System roles (tenant_id = NULL)
    'roles' => [
        'owner' => ['name' => 'เจ้าของร้าน', 'permissions' => ['*']],
        'manager' => ['name' => 'ผู้จัดการ', 'permissions' => ['*', '!members.manage']],
        'cashier' => ['name' => 'แคชเชียร์', 'permissions' => [
            'stores.view', 'catalog.view', 'orders.view', 'orders.create', 'kitchen.view',
            'payments.view', 'payments.create', 'cashier.operate', 'cashier.close', 'print.create',
        ]],
        'kitchen' => ['name' => 'ครัว', 'permissions' => [
            'stores.view', 'catalog.view', 'orders.view', 'kitchen.view', 'kitchen.update', 'print.create',
        ]],
    ],
];
