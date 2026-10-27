<?php

/*
|--------------------------------------------------------------------------
| CORS — เปิดให้ frontend ที่อยู่คนละ origin กับ API เรียกได้เฉพาะที่อนุญาต
|--------------------------------------------------------------------------
|
| SPA หน้าร้าน (/pos ทั้งแบบ path และ subdomain) เรียก API ด้วย URL relative จึงเป็น same-origin ไม่ต้องใช้ CORS
| ใส่ origin ใน POS_CORS_ALLOWED_ORIGINS เฉพาะเมื่อ deploy frontend แยกโดเมน เช่น https://app.example.com
| (คั่นด้วย comma, ต้องมี scheme และไม่มี / ท้าย) — ว่าง = ไม่อนุญาต cross-origin เลย
|
| API ยืนยันตัวตนด้วย Bearer token ไม่ใช้ cookie → supports_credentials = false
| webhook ของ payment provider เป็น server-to-server ไม่ผ่าน CORS
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('POS_CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Device-Code', 'X-Request-Id', 'X-Requested-With', 'X-Store-Id', 'X-Tenant-Id'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
