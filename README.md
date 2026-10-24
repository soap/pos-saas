# POS SaaS — Laravel API (MVP ร้านก๋วยเตี๋ยว)

Backend API ตามเอกสาร **Laravel POS SaaS — ERD & Database Schema Specification v1.1**
(Multi-tenant / Multi-store, Laravel 12 + PostgreSQL + Sanctum, REST `/api/v1`)

> Frontend (Vue 3 + TypeScript: หน้าจอ POS และ Kitchen Dashboard) ยังไม่อยู่ในรอบนี้ — API พร้อมให้เชื่อมต่อ

---

## 1. ติดตั้งบนเครื่อง (Laragon / Herd + PostgreSQL)

ต้องมี: PHP 8.2+ (extension `pdo_pgsql`, `pgsql`, `mbstring`, `intl` แนะนำ), Composer 2, PostgreSQL 14+ (แนะนำ 16)

```bash
# 1) ติดตั้ง dependencies
composer install

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

# 4) รัน server
php artisan serve
```

**Laragon (Windows):** เปิด extension `pdo_pgsql` และ `pgsql` ที่ Menu → PHP → Extensions แล้ว restart
**Herd:** PostgreSQL มีใน Herd Pro (Services) หรือติดตั้ง PostgreSQL แยกได้

### บัญชีตัวอย่าง (รหัสผ่าน `password`)

| อีเมล | บทบาท |
| --- | --- |
| `admin@pos.test` | Platform admin (จัดการ plans / tenants) |
| `owner@pos.test` | เจ้าของร้าน "ร้านก๋วยเตี๋ยว ป.ประทีป" สาขาอ่าวนาง |
| `cashier@pos.test` | แคชเชียร์ |
| `kitchen@pos.test` | ครัว |

เมนูตัวอย่าง: ก๋วยเตี๋ยวหมู/เนื้อ/เรือ, เย็นตาโฟ (ตัวเลือก: เส้น, น้ำซุป, พิเศษ, เพิ่มไข่ ฯลฯ), ของทานเล่น, เครื่องดื่ม

### งานเบื้องหลัง (Scheduler)

```bash
php artisan schedule:work     # dev: รัน outbox:publish ทุกนาที + idempotency:prune ทุกชั่วโมง
```

Production ให้ตั้ง cron/Task Scheduler เรียก `php artisan schedule:run` ทุกนาที

---

## 2. ทดสอบ

```bash
php artisan test                       # 50 tests ตาม acceptance criteria (spec §10, D7) — ใช้ฐานข้อมูล pos_test
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

## 3. การเรียก API

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
GET  /api/v1/menu                       → เมนู + ราคาสาขา + modifier groups
POST /api/v1/cashier-shifts/open        {"opening_float_satang":100000}
POST /api/v1/orders                     {"order_type":"dine_in","table_label":"โต๊ะ 5",
                                         "items":[{"product_id":"…","quantity":1,"modifier_option_ids":["…","…"],"notes":"ไม่ใส่ผงชูรส"}]}
POST /api/v1/orders/{id}/send-to-kitchen
POST /api/v1/orders/{id}/payments       {"method":"cash","tendered_satang":10000}   หรือ  {"method":"qr"}
GET  /api/v1/orders/{id}/receipt        → ข้อมูลใบเสร็จสำหรับ browser print
```

Kitchen Dashboard (polling):

```http
GET  /api/v1/kitchen/tickets                                   → full refresh + server_time
GET  /api/v1/kitchen/tickets?updated_after=<server_time ครั้งก่อน>  → เฉพาะที่เปลี่ยน (merge ด้วย id)
GET  /api/v1/kitchen/tickets?…&limit=50&cursor=<next_cursor>     → หน้าถัดไปเมื่อ has_more=true
POST /api/v1/kitchen/tickets/{id}/acknowledge | ready | served | cancel
```

ผลลัพธ์แบ่งหน้าแบบ keyset ตาม `(updated_at, id)` (limit สูงสุด 200) — ถ้า `has_more=true` ให้เรียกซ้ำด้วย query เดิม + `cursor=next_cursor`
จนหมด แล้วใช้ `server_time` ของหน้าแรกเป็น `updated_after` รอบถัดไป; หน้าจอเรียงการ์ดตาม `sent_at` เอง

Error response รูปแบบเดียวทั้งระบบ:

```json
{ "error": { "code": "shift_not_open", "message": "ยังไม่ได้เปิดกะแคชเชียร์", "details": {} }, "request_id": "…" }
```

### Endpoint ทั้งหมด (64 routes)

| กลุ่ม | Endpoints |
| --- | --- |
| Auth | `POST auth/login`, `POST auth/logout`, `GET me`, `GET tenants`, `GET context` |
| Tenancy | `GET/POST stores`, `GET/POST members`, `DELETE members/{id}/roles/{role}` |
| Catalog | `GET/POST categories`, `PATCH categories/{id}`, `GET/POST products`, `PATCH products/{id}`, `PUT products/{id}/store-price`, `GET/POST modifier-groups`, `PATCH modifier-options/{id}`, `GET menu` |
| Orders | `GET/POST orders`, `GET orders/{id}`, `GET orders/{id}/events`, `GET orders/{id}/receipt`, `POST orders/{id}/items`, `PATCH/DELETE orders/{id}/items/{item}`, `POST orders/{id}/discount`, `POST orders/{id}/cancel` |
| Kitchen | `POST orders/{id}/send-to-kitchen`, `GET kitchen/tickets`, `GET kitchen/tickets/{id}`, `POST kitchen/tickets/{id}/{acknowledge,ready,served,cancel}` |
| Payments | `POST orders/{id}/payments`, `GET payments/{id}?refresh=1`, `POST payments/{id}/cancel`, `POST payments/{id}/refunds`, `POST webhooks/payments/{provider}` |
| Cashier | `GET cashier-shifts`, `GET cashier-shifts/current`, `GET cashier-shifts/{id}`, `POST cashier-shifts/open`, `POST cashier-shifts/{id}/close`, `POST cash-movements` |
| Reports | `GET reports/sales/daily?from=&to=`, `GET reports/sales/products?from=&to=` |
| Devices | `GET/POST devices`, `POST devices/{id}/heartbeat`, `GET/POST print-jobs`, `POST print-jobs/{id}/result`, `POST print-jobs/{id}/retry` |
| SaaS Admin | `GET/POST admin/plans`, `GET/POST admin/tenants`, `POST admin/subscriptions`, `GET admin/tenants/{id}/usage` |

---

## 4. โครงสร้างโค้ด (Modular Monolith — spec §7.1)

```
app/
  Modules/
    Tenancy/    Models (Tenant, Store, memberships), Middleware (ResolveTenant/Store, RequirePermission), TenantProvisioner
    Identity/   User, Role, Permission, MembershipRole, RbacService, AuthController
    Catalog/    Category, Product, ModifierGroup/Option, MenuService (ราคาสาขา)
    Ordering/   Order, OrderItem (+snapshot), OrderEvent, OrderService, OrderLineBuilder
    Kitchen/    KitchenTicket (+revision), KitchenService (state transitions)
    Payments/   Contracts/PaymentProviderInterface, DTOs, Providers/MockQrProvider, PaymentService, WebhookService, RefundService
    Cashier/    CashierShift, CashMovement, CashierService
    Reporting/  SalesReport
    Billing/    Plan, Subscription, SubscriptionGate (plan limits)
    Devices/    StoreDevice, PrintJob, DeviceEvent, PrintService
    Audit/      AuditLog, AuditLogger
  Support/
    Tenancy/    TenantContext, BelongsToTenant / BelongsToStore (global scope)
    Idempotency/ Idempotent middleware
    Outbox/     Outbox, outbox:publish command
    Money/      Satang (สตางค์ ↔ akaunting/laravel-money)
    Errors/     ApiException
database/migrations/   10 ไฟล์ตามลำดับ spec §9 (+ hardware ภาคผนวก C5)
tests/Feature/         Isolation, Ordering, Kitchen, Payment, CashierAndAuth
scripts/concurrency_check.php
```

---

## 5. การตัดสินใจที่ใช้ (ภาคผนวก B)

| ประเด็น | ที่ตัดสินใจ |
| --- | --- |
| ภาษี | **ไม่คิด VAT** (`tax_satang = 0`, มี `tax_profile` ใน store_products เผื่ออนาคต) |
| Cashier shift | **1 กะเปิดต่อ store** — partial unique index `cashier_shifts_one_open_per_store` |
| เลขออเดอร์ | เริ่ม 1 ใหม่ทุก `business_date` ต่อสาขา, lock แถว `store_order_counters` (`SELECT … FOR UPDATE`) |
| business_date | คำนวณจาก timezone ของสาขา ณ เวลาสร้างออเดอร์ |
| Order state | `draft → placed → preparing → ready → served → completed` / `cancelled` (คำนวณจาก kitchen tickets + การชำระเงิน) |
| Kitchen ticket | `queued → preparing → ready → served` / `cancelled`; กดซ้ำสถานะเดิม = สำเร็จ (idempotent), ย้อนกลับ/ข้ามขั้น = 409 |
| Payment state | cash: `succeeded` ทันที; qr: `pending → processing → succeeded/failed/cancelled`; refund → `partially_refunded/refunded` |
| ปิดออเดอร์ | `completed` เมื่อยอดชำระสุทธิ **เท่ากับ** ยอดออเดอร์พอดี + ส่งครัวครบ + ticket ทั้งหมด `served` (ถ้าสาขาตั้ง `settings.kitchen_enabled=false` ไม่ต้องรอครัว) |
| รับเงินเกิน | ถ้ายกเลิก ticket/ให้ส่วนลดหลังชำระแล้ว ออเดอร์จะมี `refund_due_satang` > 0 (บันทึก event `order.refund_due`) และไม่ปิดจนกว่าจะคืนเงินส่วนเกิน |
| Refund ผ่าน provider | สร้าง refund `pending` (จองยอด) ภายใต้ lock → เรียก provider นอก transaction → `succeeded` หรือ `failed` (เก็บ record + audit `payment.refund_failed` ไว้เสมอ) |
| ยกเลิกออเดอร์ | ต้องไม่มีการชำระค้าง/สำเร็จ — ต้อง refund ก่อน |
| Idempotency scope | `(tenant_id, actor_user_id, operation, key)` ไม่รวม store_id ที่อาจเป็น NULL; เก็บเฉพาะ response 2xx, อายุ 24 ชม. |
| Webhook dedupe | `unique(provider_code, provider_account_key, external_event_id)`; account key ใช้ `''` แทน NULL |
| System roles | `owner`, `manager`, `cashier`, `kitchen` (tenant_id = NULL) กำหนดใน `config/pos.php` |
| Subscription | tenant ต้องมี subscription สถานะ trialing/active/past_due (ปิดได้ด้วย `POS_ENFORCE_SUBSCRIPTION=false`) |
| UUID | Laravel `HasUuids` (UUID v7 แบบเรียงตามเวลา — ดีต่อ index) |
| เงิน | DB/API เป็น BIGINT สตางค์เหมือนเดิม; คำนวณใน service ด้วย `akaunting/laravel-money` (THB) ผ่าน `App\Support\Money\Satang` — `Satang::money()` สร้าง Money, `Satang::of()` แปลงกลับเป็น int และปฏิเสธเศษสตางค์ (Money ภายในคืนค่า float ห้ามเก็บลง DB ตรง ๆ); ไม่ใช้ `MoneyCast` ของ package เพราะเก็บเป็น JSON ไม่ตรงกับ schema |

---

## 6. สิ่งที่ยังไม่ได้ทำ / ต้องทำก่อน production

- Frontend Vue 3 (POS, Kitchen Dashboard พร้อมเสียงแจ้งเตือน)
- QR provider จริง (implement `PaymentProviderInterface` + ใส่ใน `config/pos.php`) — refund ของ provider จริงอาจเป็น async ต้องเพิ่ม webhook ของ refund
- Local Print Bridge (มี API คิวงานพิมพ์แล้ว: `GET print-jobs`, `POST print-jobs/{id}/result`)
- Redis + Horizon สำหรับ queue/cache (ตอนนี้ใช้ database driver — เปลี่ยนได้ใน `.env`)
- รอบชำระค่า subscription (`subscription_payments`) — มีตารางแล้ว ยังไม่มี flow เก็บเงิน
- Retention policy ของ webhook payload / audit log, backup + restore test, structured logging / alert
- CORS allowlist สำหรับโดเมน frontend (`php artisan config:publish cors` แล้วแก้ `allowed_origins`)
