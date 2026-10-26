<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Payments\Http\Requests\PaymentAccountRequest;
use App\Modules\Payments\Http\Resources\PaymentAccountResource;
use App\Modules\Payments\Models\PaymentAccount;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** บัญชีรับชำระ QR ของ tenant (spec ภาคผนวก B7) — ต้องมีสิทธิ์ payments.configure */
class PaymentAccountController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** GET /payment-accounts */
    public function index(): AnonymousResourceCollection
    {
        return PaymentAccountResource::collection(PaymentAccount::query()->orderBy('created_at')->get());
    }

    /** POST /payment-accounts — ตอบ webhook_url ให้นำไปตั้งใน dashboard ของ provider */
    public function store(PaymentAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $account = PaymentAccount::create([
            'provider_code' => $data['provider_code'],
            'name' => $data['name'],
            'account_key' => 'pa_'.Str::lower(Str::random(32)),
            'credentials' => $data['credentials'],
            'settings' => $data['settings'] ?? [],
            'status' => $data['status'] ?? 'active',
        ]);
        $this->audit->log('payment_account.created', $account, ['provider_code' => $account->provider_code, 'name' => $account->name]);

        return (new PaymentAccountResource($account))->response()->setStatusCode(201);
    }

    /** PATCH /payment-accounts/{id} — ส่ง credentials เพื่อหมุนคีย์ (ต้องส่งครบทุกช่อง) */
    public function update(PaymentAccountRequest $request, string $id): PaymentAccountResource
    {
        $account = PaymentAccount::query()->findOrFail($id);
        $data = $request->validated();
        $account->update($data);
        $this->audit->log('payment_account.updated', $account, [
            'fields' => array_keys($data),
            'credentials_rotated' => isset($data['credentials']),
        ]);

        return new PaymentAccountResource($account);
    }

    /** PUT /stores/{id}/qr-payment-account — เลือกบัญชีรับ QR ของสาขา (null = กลับไปใช้ default ของระบบ) */
    public function assignToStore(Request $request, string $id): JsonResponse
    {
        $store = Store::query()->findOrFail($id);
        $data = $request->validate([
            // Rule::exists ไม่ผ่าน global scope → กรอง tenant เอง (ใช้บัญชีของ tenant อื่นไม่ได้)
            'payment_account_id' => ['present', 'nullable', 'uuid', Rule::exists('payment_accounts', 'id')
                ->where('tenant_id', $store->tenant_id)->where('status', 'active')],
        ]);
        $store->update(['qr_payment_account_id' => $data['payment_account_id']]);
        $this->audit->log('store.qr_payment_account_changed', $store, ['payment_account_id' => $data['payment_account_id']]);

        return response()->json(['data' => ['store_id' => $store->id, 'qr_payment_account_id' => $store->qr_payment_account_id]]);
    }
}
