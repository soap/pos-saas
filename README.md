# POS SaaS — Laravel (MVP ร้านก๋วยเตี๋ยว)

ระบบ POS แบบ SaaS ตามเอกสาร **Laravel POS SaaS — ERD & Database Schema Specification v1.1** (`docs/`)
Multi-tenant / Multi-store บน Laravel 12 + PostgreSQL + Sanctum ประกอบด้วย 3 ส่วน:

| ส่วน | URL | เทคโนโลยี |
| --- | --- | --- |
| REST API | `/api/v1` | Laravel + Sanctum (Bearer token) |
| หน้าร้าน (POS, จอครัว, หลังร้าน) | `/pos` | Vue 3 + TypeScript + Pinia + Tailwind 4 (Vite) |
| Platform admin (plans / tenants) | `/admin` | Filament 5 — บังคับ MFA (passkey หรือ authenticator app) |

---

## 1. ติดตั้งบนเครื่อง (Laragon / Herd + PostgreSQL)

ต้องมี: PHP 8.2+ (extension `pdo_pgsql`, `pgsql`, `mbstring`, `gd` สำหรับย่อรูปสินค้า, `intl` แนะนำ), Composer 2,
Node.js 20.19+ หรือ 22.12+ (Vite 7), PostgreSQL 14+ (แนะนำ 16)

```bash
# 1) ติดตั้ง dependencies
composer install
npm install

# 2) ตั้งค่า environment
copy .env.example .env          # (Windows)  หรือ  cp .env.example .env
php artisan key:generate
```

แก้ `.env` ให้ตรงกับ PostgreSQL ของคุณ:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pos
DB_USERNAME=pos
DB_PASSWORD=secret
```

สร้างฐานข้อมูล (ผ่าน psql / pgAdmin / HeidiSQL ของ Laragon):

```sql
CREATE USER pos WITH PASSWORD 'secret';
CREATE DATABASE pos OWNER pos;
CREATE DATABASE pos_test OWNER pos;   -- สำหรับ automated tests
```

```bash
# 3) สร้างตาราง + ข้อมูลตัวอย่าง
php artisan migrate --seed

# 4) รัน server + queue worker + Reverb + Vite พร้อมกัน
composer run dev
```

หรือ build frontend ครั้งเดียวด้วย `npm run build` แล้วรัน `php artisan serve` + `php artisan queue:work` + `php artisan reverb:start` เอง

**Laragon (Windows):** เปิด extension `pdo_pgsql`, `pgsql`, `gd` ที่ Menu → PHP → Extensions แล้ว restart
**Herd:** PostgreSQL มีใน Herd Pro (Services) หรือติดตั้ง PostgreSQL แยกได้

### บัญชีตัวอย่าง (รหัสผ่าน `password`)

| อีเมล | บทบาท | เข้าที่ |
| --- | --- | --- |
| `admin@pos.test` | Platform admin (จัดการ plans / tenants) | `/admin` (ตั้ง MFA ครั้งแรกที่ login) |
| `owner@pos.test` | เจ้าของร้าน "ร้านก๋วยเตี๋ยว ป.ประทีป" (slug `p-prateep`) สาขาอ่าวนาง | `/pos` |
| `cashier@pos.test` | แคชเชียร์ | `/pos` |
| `kitchen@pos.test` | ครัว (login แล้วเข้าจอครัวอัตโนมัติ) | `/pos` |

เมนูตัวอย่าง: ก๋วยเตี๋ยวหมู/เนื้อ/เรือ, เย็นตาโฟ (ตัวเลือก: เส้น, น้ำซุป, พิเศษ, เพิ่มไข่ ฯลฯ), ของทานเล่น, เครื่องดื่ม

### งานเบื้องหลัง (Scheduler + Queue worker)

```bash
php artisan schedule:work     # dev: รัน outbox:publish ทุกนาที + idempotency:prune ทุกชั่วโมง
php artisan queue:work        # ย่อรูปสินค้า (thumb / preview) และ queued job อื่น ๆ  (composer run dev รันให้แล้ว)
```

Production ให้ตั้ง cron/Task Scheduler เรียก `php artisan schedule:run` ทุกนาที และรัน `queue:work` ค้างไว้ด้วย supervisor/systemd
(`QUEUE_CONNECTION` ต้องไม่ใช่ `sync` — ถ้าไม่มี worker รูปที่อัปโหลดจะไม่ถูกย่อ เมนูจะใช้ไฟล์ต้นฉบับแทน)

### Realtime (Laravel Reverb)

```bash
php artisan reverb:start      # WebSocket server (composer run dev รันให้แล้ว)
```

- ทุกการเปลี่ยนของออเดอร์ (`OrderService::event()`) ส่งสัญญาณ `order.changed` เข้า private channel `store.{storeId}`
  มีแค่ `order_id`, `event_type`, `payment_id` — จอครัว, ผังโต๊ะ, ออเดอร์ที่เปิดอยู่ และหน้ารอ QR ดึงข้อมูลล่าสุดจาก API เอง
- สัญญาณถูก queue หลัง commit → ต้องมี `queue:work` (ใช้ `--sleep=1` เพื่อลด latency ของ database queue)
- ขอสิทธิ์ channel ที่ `POST /api/v1[/t/{slug}]/broadcasting/auth` (ผ่าน tenant + store middleware เหมือน API อื่น)
- polling ยังทำงานเป็นทางสำรอง: ขณะต่อ realtime อยู่ poll ทุก 60 วินาที, หลุดเมื่อไรกลับไป poll ถี่ตามเดิมทันที
- Production: เปิด Reverb หลัง reverse proxy (TLS) แล้วตั้ง `POS_REALTIME_HOST/PORT/SCHEME` เป็นที่อยู่ที่ browser ต่อเข้า,
  รัน `reverb:start` ด้วย supervisor/systemd และตั้ง `BROADCAST_CONNECTION=log` ถ้าต้องการปิด realtime

### ตัวแปร environment ของระบบ

| ตัวแปร | ค่าเริ่มต้น | ความหมาย |
| --- | --- | --- |
| `POS_TENANT_BASE_DOMAIN` | ว่าง (ปิด) | เปิดการระบุร้านด้วย subdomain เช่น `pos.example.com` → `p-prateep.pos.example.com` |
| `POS_ENFORCE_SUBSCRIPTION` | `true` | tenant ต้องมี subscription สถานะ trialing/active/past_due |
| `POS_TAX_MODE` / `POS_VAT_RATE_PERCENT` | `none` / `7` | ภาษีตั้งต้นของสาขาที่ไม่ได้ตั้ง `settings.tax_mode` |
| `POS_QR_PROVIDER` | `mock` | provider ของสาขาที่ยังไม่เลือกบัญชี QR (production ควรว่าง) |
| `MOCK_QR_WEBHOOK_SECRET`, `MOCK_QR_ACCOUNT_KEY` | — | ใช้กับ mock provider เท่านั้น |
| `POS_KDS_POLL_SECONDS` / `POS_KDS_LATE_MINUTES` | `3` / `15` | รอบ polling ของจอครัว / นาทีที่ถือว่างานล่าช้า |
| `MEDIA_DISK` | `local` | disk (private) ของรูปสินค้า — `local` = `storage/app/private`, production ใช้ `s3` ได้ |
| `POS_CORS_ALLOWED_ORIGINS` | ว่าง (ไม่เปิด CORS) | origin ของ frontend ที่อยู่คนละโดเมนกับ API คั่นด้วย comma เช่น `https://app.example.com` (SPA `/pos` เป็น same-origin ไม่ต้องใส่) |
| `POS_REALTIME_HOST` / `_PORT` / `_SCHEME` | ค่า `REVERB_*` | ที่อยู่ Reverb ที่ browser ต่อเข้า (เช่นหลัง reverse proxy) — Reverb รับ WebSocket จาก host ของ `APP_URL`, subdomain ของร้าน และ `POS_CORS_ALLOWED_ORIGINS` เท่านั้น |

---

## 2. หน้าร้าน (Vue SPA ที่ `/pos`)

| URL | ใช้เมื่อ |
| --- | --- |
| `/pos` | login แล้วเลือกร้าน/สาขา |
| `/pos/t/{slug}` | ปักหมุดร้านด้วย path |
| `{slug}.<POS_TENANT_BASE_DOMAIN>/pos` | ปักหมุดร้านด้วย subdomain |

หน้าจอ:

- **หน้าขาย** — ผังโต๊ะ (อัปเดตทันทีผ่าน Reverb, สำรองด้วยการรีเฟรชทุก 15 วินาที), เมนูพร้อมรูปสินค้า, ตะกร้า, ส่งครัว, ส่วนลด, ย้ายโต๊ะ, ยกเลิกออเดอร์,
  ชำระเงินสด/QR, คืนเงิน, ใบเสร็จ, เปิด/ปิดกะ
  - แสดงสถานะครัวรายรายการ: ยังไม่ส่งครัว → ส่งคำสั่งแล้ว (`queued`) → กำลังทำ (`preparing`) → พร้อมเสิร์ฟ (`ready`) → เสิร์ฟแล้ว (`served`)
  - ดึงออเดอร์ใหม่ทันทีเมื่อครัวเปลี่ยนสถานะ (สำรองด้วย polling ทุก 10 วินาทีขณะยังมีงานค้างในครัว); พนักงานหน้าร้านกด "เสิร์ฟแล้ว" ได้เอง (ออเดอร์ปิดได้เมื่อทุก ticket `served`)
- **จอครัว** (`/pos/kitchen`) — การ์ดงานแยกตามจุดในครัว (station), เสียงแจ้งเตือนงานใหม่ (ต้องแตะเปิดเสียงก่อน), เตือนงานเกินเวลา, Wake Lock กันจอดับ
- **หลังร้าน** (`/pos/manage`) — รายงานยอดขาย, สินค้า (ราคาสาขา, รูปสินค้า), หมวดหมู่, ตัวเลือกเสริม, จุดในครัว, โต๊ะ, สาขา, พนักงาน, บัญชีรับเงิน QR
  (เมนูแสดงตามสิทธิ์ของผู้ใช้)

---

## 3. ทดสอบ

```bash
php artisan test               # feature + unit tests (ใช้ฐานข้อมูล pos_test, queue แบบ sync)
npm run typecheck              # vue-tsc ของ frontend
```

ทดสอบ concurrency จริง (request พร้อมกันหลายรายการ):

```bash
# terminal 1  (Windows PowerShell: $env:PHP_CLI_SERVER_WORKERS=8; php artisan serve --port=8000)
PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8000

# terminal 2
php scripts/concurrency_check.php http://127.0.0.1:8000 owner@pos.test password
```

ตรวจ: เลขออเดอร์ไม่ซ้ำเมื่อสร้างพร้อมกัน 20 รายการ, Idempotency-Key ซ้ำพร้อมกัน → ได้ 1 ออเดอร์,
refund พร้อมกันไม่เกินยอด, ปิดกะพร้อมกันปิดได้ครั้งเดียว (script จะปิดกะที่เปิดอยู่เมื่อจบ)

จำลองลูกค้าสแกนจ่าย QR (Mock provider):

```bash
php artisan mock-qr:pay <payment_id>              # ส่ง webhook ที่ sign ถูกต้อง → payment succeeded
php artisan mock-qr:pay <payment_id> --status=failed
```

---

## 4. การเรียก API

Header ที่ใช้:

| Header | ใช้เมื่อ |
| --- | --- |
| `Authorization: Bearer <token>` | ทุก endpoint ยกเว้น login / webhook |
| `X-Tenant-Id: <uuid>` | ทางเลือกในการระบุ tenant (ดูด้านล่าง) |
| `X-Store-Id: <uuid>` | endpoint ระดับสาขา (ต้องมี store membership) |
| `Idempotency-Key: <8-128 ตัวอักษร>` | สร้างออเดอร์ / เพิ่มรายการ / ส่งครัว / รับชำระ / คืนเงิน / เงินเข้าออกลิ้นชัก |

การระบุ tenant (endpoint ระดับร้าน) ทำได้ 3 แบบ — ระบบตรวจกับ membership เสมอ การระบุเป็นแค่การ "เลือก":

| แบบ | ตัวอย่าง | หมายเหตุ |
| --- | --- | --- |
| path (แนะนำ) | `GET /api/v1/t/p-prateep/menu` | ใช้ `slug` จาก `GET /me` |
| subdomain | `GET https://p-prateep.pos.example.com/api/v1/menu` | เปิดด้วย `POS_TENANT_BASE_DOMAIN=pos.example.com` ต้องมี wildcard DNS + TLS |
| header | `X-Tenant-Id: <uuid>` | สำหรับ client เดิม / script |

ถ้าส่งหลายแบบพร้อมกันต้องชี้ tenant เดียวกัน ไม่งั้นได้ `400 tenant_context_conflict`
slug ต้องเป็น DNS label (`a-z`, `0-9`, `-` ยาว ≤ 63) และห้ามใช้คำสงวนใน `config/pos.php` (`tenancy.reserved_slugs`)

เงินทุกจำนวนเป็น **สตางค์ (integer)** เช่น `5000` = 50.00 บาท

ตัวอย่าง flow หน้าร้าน:

```http
POST /api/v1/auth/login                 {"email":"cashier@pos.test","password":"password"}
GET  /api/v1/me                         → tenants[].slug, tenants[].stores[].id  (endpoint ถัดไปละการระบุ tenant ไว้)
GET  /api/v1/menu                       → เมนู + ราคาสาขา + modifier groups + image_url
POST /api/v1/cashier-shifts/open        {"opening_float_satang":100000}
POST /api/v1/orders                     {"order_type":"dine_in","dining_table_id":"…",
                                         "items":[{"product_id":"…","quantity":1,"modifier_option_ids":["…","…"],"notes":"ไม่ใส่ผงชูรส"}]}
POST /api/v1/orders/{id}/send-to-kitchen
GET  /api/v1/orders/{id}                → items[], kitchen_tickets[].status, balance_due_satang
POST /api/v1/kitchen/tickets/{id}/served   (หน้าร้านยืนยันการเสิร์ฟ — สิทธิ์ orders.create)
POST /api/v1/orders/{id}/payments       {"method":"cash","tendered_satang":10000}   หรือ  {"method":"qr"}
GET  /api/v1/orders/{id}/receipt        → ข้อมูลใบเสร็จสำหรับ browser print
```

ออเดอร์ทานที่ร้านผูกโต๊ะได้ด้วย `dining_table_id` (หรือใส่ `table_label` อิสระ) และย้ายโต๊ะด้วย `POST orders/{id}/move-table`

Kitchen Dashboard (polling):

```http
GET  /api/v1/kitchen/tickets                                   → full refresh + server_time
GET  /api/v1/kitchen/tickets?updated_after=<server_time ครั้งก่อน>  → เฉพาะที่เปลี่ยน (merge ด้วย id)
GET  /api/v1/kitchen/tickets?…&limit=50&cursor=<next_cursor>     → หน้าถัดไปเมื่อ has_more=true
GET  /api/v1/kitchen/tickets?station=<uuid>|main                  → เฉพาะจุดในครัว (main = ครัวหลัก)
POST /api/v1/kitchen/tickets/{id}/acknowledge | ready | served | cancel
```

ผลลัพธ์แบ่งหน้าแบบ keyset ตาม `(updated_at, id)` (limit สูงสุด 200) — ถ้า `has_more=true` ให้เรียกซ้ำด้วย query เดิม + `cursor=next_cursor`
จนหมด แล้วใช้ `server_time` ของหน้าแรกเป็น `updated_after` รอบถัดไป; หน้าจอเรียงการ์ดตาม `sent_at` เอง
ส่งครัว 1 ครั้งได้ revision เดียว แต่แตกเป็น ticket ละจุดในครัวตาม `products.kitchen_station_id` (null = ครัวหลัก)

### ชำระเงิน QR

บัญชีรับเงิน QR ตั้งต่อ tenant (`payment-accounts`, credentials เข้ารหัสด้วย `APP_KEY`) แล้วเลือกให้สาขาด้วย `PUT stores/{id}/qr-payment-account`

| Provider | การทำงาน | คืนเงิน |
| --- | --- | --- |
| `mock` | QR จำลอง + webhook ผ่าน `mock-qr:pay` (dev/test) | ผ่าน provider |
| `omise` | PromptPay ผ่าน Omise (`soap/laravel-omise`), webhook ยืนยันผล | โอนคืนเอง |
| `easyslip` | สร้าง PromptPay QR จากเลขพร้อมเพย์ของร้าน → ลูกค้าโอน → `POST payments/{id}/slip` ส่ง QR บนสลิปให้ EasySlip ตรวจแบบ async → ผลมาทาง webhook (หรือ poll `GET payments/{id}?refresh=1`) | โอนคืนเอง |

Webhook: `POST /api/v1/webhooks/payments/{provider}/{account_key}` (mock ไม่มี `account_key`)

### รูปสินค้า

หลายรูปต่อสินค้า (สูงสุด 10 รูป, JPG/PNG/WebP ≤ 5 MB, สิทธิ์ `catalog.manage`):

```http
POST   /api/v1/products/{id}/images              multipart: image=<file>   → 201 product (+ images[])
PUT    /api/v1/products/{id}/primary-image       {"media_id": 12}          → เลือกรูปที่แสดงในเมนู
DELETE /api/v1/products/{id}/images/{mediaId}
GET    /api/v1/products                          → images[]: {id, name, is_primary, thumb_url, preview_url}
GET    /api/v1/menu                              → products[].image_url (thumb ของรูปที่เลือก, null = ไม่มีรูป)
```

- รูปที่แสดง = `products.primary_media_id`; ถ้าไม่ได้เลือกหรือรูปนั้นถูกลบ ใช้รูปแรกตามลำดับ
- URL รูปเป็น signed URL แบบ relative (`/media/{uuid}/{thumb|preview}?expires=…&signature=…`) ใส่ใน `<img>` ได้ตรง ๆ
  ไม่ต้องส่ง token; หมดอายุต้นวันถัดไปอีกวัน (24–48 ชม.) และภายในวันเดียวกันได้ URL เดิม จึง cache ได้
- ระหว่างที่ worker ยังย่อรูปไม่เสร็จ `thumb_url`/`preview_url` จะชี้ไฟล์ต้นฉบับไปก่อน

### Error response

รูปแบบเดียวทั้งระบบ:

```json
{ "error": { "code": "shift_not_open", "message": "ยังไม่ได้เปิดกะแคชเชียร์", "details": {} }, "request_id": "…" }
```

validation error ได้ `422 validation_failed` และ `details` เป็นข้อความรายช่อง

### Endpoint ทั้งหมด

รายการล่าสุดดูได้จาก `php artisan route:list --path=api/v1` (ทุก route ระดับร้านมีชุด `/t/{slug}/…` ด้วย)

| กลุ่ม | Endpoints |
| --- | --- |
| Auth | `POST auth/login`, `POST auth/logout`, `GET me`, `GET tenants`, `GET context` |
| Tenancy | `GET/POST stores`, `PATCH stores/{id}`, `GET/POST members`, `PATCH members/{id}`, `DELETE members/{id}/roles/{role}` |
| Catalog | `GET/POST categories`, `PATCH categories/{id}`, `GET/POST products`, `PATCH products/{id}`, `POST products/{id}/images`, `DELETE products/{id}/images/{mediaId}`, `PUT products/{id}/primary-image`, `PUT/DELETE products/{id}/store-price`, `GET store-prices`, `GET/POST modifier-groups`, `PATCH modifier-groups/{id}`, `POST modifier-groups/{id}/options`, `PATCH modifier-options/{id}`, `GET menu` |
| Tables | `GET/POST tables`, `PATCH tables/{id}` |
| Orders | `GET/POST orders`, `GET orders/{id}`, `GET orders/{id}/events`, `GET orders/{id}/receipt`, `POST orders/{id}/items`, `PATCH/DELETE orders/{id}/items/{item}`, `POST orders/{id}/discount`, `POST orders/{id}/move-table`, `POST orders/{id}/cancel` |
| Kitchen | `GET/POST kitchen-stations`, `PATCH kitchen-stations/{id}`, `POST orders/{id}/send-to-kitchen`, `GET kitchen/tickets`, `GET kitchen/tickets/{id}`, `POST kitchen/tickets/{id}/{acknowledge,ready,served,cancel}` |
| Payments | `GET/POST payment-accounts`, `PATCH payment-accounts/{id}`, `PUT stores/{id}/qr-payment-account`, `POST orders/{id}/payments`, `GET payments/{id}?refresh=1`, `POST payments/{id}/slip`, `POST payments/{id}/cancel`, `POST payments/{id}/refunds`, `POST webhooks/payments/{provider}/{account?}` |
| Cashier | `GET cashier-shifts`, `GET cashier-shifts/current`, `GET cashier-shifts/{id}`, `POST cashier-shifts/open`, `POST cashier-shifts/{id}/close`, `POST cash-movements` |
| Reports | `GET reports/sales/daily?from=&to=`, `GET reports/sales/products?from=&to=` |
| Devices | `GET/POST devices`, `POST devices/{id}/heartbeat`, `GET/POST print-jobs`, `POST print-jobs/{id}/result`, `POST print-jobs/{id}/retry` |

นอก `/api/v1`: `GET /media/{uuid}/{conversion?}` (signed) — เปิดไฟล์รูปสินค้า

SaaS admin (plans, tenants, subscriptions, usage) จัดการผ่าน Filament ที่ `/admin` เท่านั้น — API `admin/*` เดิม (`routes/api-admin.php`)
ปิดไว้ไม่ให้มีทางเข้าที่ไม่บังคับ MFA (ยังมี test ครอบคลุมผ่าน `tests/Support/MountsAdminApiRoutes.php`)

---

## 5. โครงสร้างโค้ด (Modular Monolith — spec §7.1)

```
app/
  Modules/
    Tenancy/    Tenant, Store, memberships, Middleware (ResolveTenant/Store, RequirePermission, RequirePlatformAdmin), TenantProvisioner
    Identity/   User, Role, Permission, MembershipRole, RbacService, AuthController
    Catalog/    Category, Product (+รูป), ModifierGroup/Option, MenuService (ราคาสาขา), ProductImageService
    Ordering/   Order, OrderItem (+snapshot), OrderEvent, DiningTable, OrderService, OrderLineBuilder
    Kitchen/    KitchenStation, KitchenTicket (+revision, ต่อ station), KitchenService (state transitions)
    Payments/   Contracts (PaymentProvider, SupportsRefunds, VerifiesSlips), Providers (Mock, Omise, EasySlip),
                PaymentAccount, PaymentService, WebhookService, RefundService, PromptPayPayload
    Cashier/    CashierShift, CashMovement, CashierService
    Reporting/  SalesReport
    Billing/    Plan, Subscription, SubscriptionGate (plan limits), BillingAdmin
    Devices/    StoreDevice, PrintJob, DeviceEvent, PrintService
    Audit/      AuditLog, AuditLogger
  Support/
    Tenancy/    TenantContext, BelongsToTenant / BelongsToStore (global scope), TenantResolver, QueueTenancy (context ของ queued job)
    Media/      TenantMedia (media ผูก tenant), TenantPathGenerator (tenants/{tenant_id}/media/{uuid}/)
    Idempotency/ Idempotent middleware
    Outbox/     Outbox, outbox:publish command
    Money/      Satang (สตางค์ ↔ akaunting/laravel-money)
    Errors/     ApiException
  Filament/     Platform admin panel (Resources: Plans, Tenants)
  Http/Controllers/  PosShellController (หน้า shell ของ SPA), MediaController (signed media)
resources/js/pos/
  api/          client (axios + tenant/store header), types
  stores/       Pinia: auth, session, pos (ตะกร้า/ออเดอร์), kitchen (polling)
  views/        Login, StoreSelect, Register (หน้าขาย), Kitchen, Receipt, manage/* (หลังร้าน)
  components/   OrderPanel, MenuPanel, TablesPanel, PaymentDialog, KitchenTicketCard, …
database/migrations/   ตามลำดับ spec §9 (+ hardware ภาคผนวก C5, payment accounts, โต๊ะ, จุดในครัว, MFA/passkey, media)
tests/Feature/         Isolation / TenantScope (path, header, subdomain), Ordering, Kitchen, KitchenStation, Payment, Omise, EasySlip,
                       PaymentAccount, CashierAndAuth, BackOffice, DiningTable, ProductImage, QueueTenancy, AdminPanel, PosSpa, …
scripts/concurrency_check.php
```

### Queued job กับ tenant

`QueueTenancy` (แนวเดียวกับ `QueueTenancyBootstrapper` ของ stancl/tenancy) ทำให้ job ทำงานใต้ tenant เดียวกับที่ dispatch:

1. ตอน dispatch แนบ `tenant_id` / `store_id` ของ `TenantContext` ปัจจุบันลง payload
2. `JobProcessing` → ตั้ง context จาก payload (ในนามระบบ: ไม่มี user, `can()` เป็น false เสมอ)
3. `JobAttempted` (ยิงใน `finally` ทั้ง worker และ sync) → คืน context เดิม — sync job จึงไม่ทำให้ request ที่ dispatch เสีย tenant

job ที่ไม่มี `tenant_id` = งานระดับ central (context ว่าง, query model ของ tenant จะ throw); tenant ใน payload หาไม่เจอ → job ล้ม

ข้อควรระวัง: `dispatch()` คืน `PendingDispatch` ที่ส่ง job ตอนถูกทำลาย — ภายใน `runAs()` ต้องใช้ closure แบบ block

```php
$ctx->runAs($tenant, $store, fn () => SomeJob::dispatch($x));                       // ผิด: dispatch หลัง runAs จบ ไม่มี tenant
$ctx->runAs($tenant, $store, function () use ($x) { SomeJob::dispatch($x); });      // ถูก
```

คำสั่งของ media-library ที่ไล่ทุก tenant (เช่น `media-library:clean`, `media-library:regenerate`) ต้องรันภายใน `withoutTenancy()`

---

## 6. การตัดสินใจที่ใช้ (ภาคผนวก B)

| ประเด็น | ที่ตัดสินใจ |
| --- | --- |
| ภาษี | ตั้งต่อสาขาด้วย `settings.tax_mode` (ค่าตั้งต้น `POS_TAX_MODE`): `none` = ไม่จด VAT (`tax_satang = 0`), `inclusive` = ราคาขายรวม VAT แล้ว (ยอดรวมไม่เปลี่ยน, `tax_satang` = ส่วน VAT ในยอดหลังหักส่วนลด, อัตรา `POS_VAT_RATE_PERCENT`) |
| Cashier shift | **1 กะเปิดต่อ store** — partial unique index `cashier_shifts_one_open_per_store` |
| เลขออเดอร์ | เริ่ม 1 ใหม่ทุก `business_date` ต่อสาขา, lock แถว `store_order_counters` (`SELECT … FOR UPDATE`) |
| business_date | คำนวณจาก timezone ของสาขา ณ เวลาสร้างออเดอร์ |
| Order state | `draft → placed → preparing → ready → served → completed` / `cancelled` (คำนวณจาก kitchen tickets + การชำระเงิน) |
| Kitchen ticket | `queued → preparing → ready → served` / `cancelled`; กดซ้ำสถานะเดิม = สำเร็จ (idempotent), ย้อนกลับ/ข้ามขั้น = 409; `served` กดได้ทั้งครัว (`kitchen.update`) และหน้าร้าน (`orders.create`) |
| จุดในครัว | ticket แยกต่อ station ตาม station ของสินค้า ณ ตอนส่ง; station ที่ปิดหรือสินค้าที่ถูกลบ → ครัวหลัก |
| Payment state | cash: `succeeded` ทันที; qr: `pending → processing → succeeded/failed/cancelled`; refund → `partially_refunded/refunded` |
| ปิดออเดอร์ | `completed` เมื่อยอดชำระสุทธิ **เท่ากับ** ยอดออเดอร์พอดี + ส่งครัวครบ + ticket ทั้งหมด `served` (ถ้าสาขาตั้ง `settings.kitchen_enabled=false` ไม่ต้องรอครัว) |
| รับเงินเกิน | ถ้ายกเลิก ticket/ให้ส่วนลดหลังชำระแล้ว ออเดอร์จะมี `refund_due_satang` > 0 (บันทึก event `order.refund_due`) และไม่ปิดจนกว่าจะคืนเงินส่วนเกิน |
| Refund ผ่าน provider | สร้าง refund `pending` (จองยอด) ภายใต้ lock → เรียก provider นอก transaction → `succeeded` หรือ `failed` (เก็บ record + audit `payment.refund_failed` ไว้เสมอ); provider ที่ไม่มี refund API → คืนแบบโอนเอง |
| ยกเลิกออเดอร์ | ต้องไม่มีการชำระค้าง/สำเร็จ — ต้อง refund ก่อน |
| Idempotency scope | `(tenant_id, actor_user_id, operation, key)` ไม่รวม store_id ที่อาจเป็น NULL; เก็บเฉพาะ response 2xx, อายุ 24 ชม. |
| Webhook dedupe | `unique(provider_code, provider_account_key, external_event_id)`; account key ใช้ `''` แทน NULL |
| System roles | `owner`, `manager`, `cashier`, `kitchen` (tenant_id = NULL) กำหนดใน `config/pos.php` |
| Subscription | tenant ต้องมี subscription สถานะ trialing/active/past_due (ปิดได้ด้วย `POS_ENFORCE_SUBSCRIPTION=false`) |
| Timezone ของ DB | session ของ Postgres ตั้งเป็น `DB_TIMEZONE` (`UTC`) ให้ตรง `app.timezone` — Laravel เขียน timestamp โดยไม่มี offset ถ้า server เป็น Asia/Bangkok เวลาจะเพี้ยน 7 ชั่วโมง |
| Platform admin | Filament `/admin` เท่านั้น บังคับ MFA (passkey หรือ TOTP); ทำงานใน platform scope (ข้าม tenant) |
| รูปสินค้า | `spatie/laravel-medialibrary`; ตาราง `media` มี `tenant_id` + global scope; ไฟล์อยู่ disk private (`MEDIA_DISK`) ที่ `tenants/{tenant_id}/media/{uuid}/` (รูปย่อใน `conversions/`) |
| เปิดไฟล์ media | ผ่าน `MediaController` ด้วย signed URL แบบ relative เท่านั้น (`<img>` ส่ง Bearer token ไม่ได้, relative เพื่อใช้ได้ทั้ง path/subdomain ของร้าน); ส่ง `Cache-Control: private, max-age=86400` |
| ย่อรูป | queued หลัง commit (`thumb` 320×320 crop, `preview` ≤ 1200 px, webp) — `QueueTenancy` พา tenant ไปให้ worker |
| UUID | Laravel `HasUuids` (UUID v7 แบบเรียงตามเวลา — ดีต่อ index) |
| เงิน | DB/API เป็น BIGINT สตางค์เหมือนเดิม; คำนวณใน service ด้วย `akaunting/laravel-money` (THB) ผ่าน `App\Support\Money\Satang` — `Satang::money()` สร้าง Money, `Satang::of()` แปลงกลับเป็น int และปฏิเสธเศษสตางค์ (Money ภายในคืนค่า float ห้ามเก็บลง DB ตรง ๆ); ไม่ใช้ `MoneyCast` ของ package เพราะเก็บเป็น JSON ไม่ตรงกับ schema |

---

## 7. สิ่งที่ยังไม่ได้ทำ / ต้องทำก่อน production

- Refund อัตโนมัติของ Omise (ตอนนี้คืนแบบโอนเอง) — refund ของ provider จริงอาจเป็น async ต้องเพิ่ม webhook ของ refund
- Local Print Bridge (มี API คิวงานพิมพ์แล้ว: `GET print-jobs`, `POST print-jobs/{id}/result`)
- Redis + Horizon สำหรับ queue/cache (ตอนนี้ใช้ database driver — เปลี่ยนได้ใน `.env`)
- เก็บรูปสินค้าบน S3 (bucket แบบ private): ตั้ง `MEDIA_DISK=s3` — รูปยังเปิดผ่าน signed route เดิม (server stream ไฟล์ให้);
  ถ้าต้องการลดโหลด server ให้เปลี่ยนไปใช้ presigned URL ของ S3 / CDN
- รอบชำระค่า subscription (`subscription_payments`) — มีตารางแล้ว ยังไม่มี flow เก็บเงิน
- Retention policy ของ webhook payload / audit log, backup + restore test, structured logging / alert
