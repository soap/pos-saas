<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\StoreMembership;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Errors\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->audit->log('auth.login_failed', $user, ['email' => mb_strtolower($data['email'])], actorId: null);
            throw new ApiException(401, 'invalid_credentials', 'อีเมลหรือรหัสผ่านไม่ถูกต้อง');
        }
        if ($user->status !== 'active') {
            $this->audit->log('auth.login_blocked', $user, [], actorId: $user->id);
            throw ApiException::forbidden('account_disabled', 'บัญชีถูกปิดใช้งาน');
        }

        $token = $user->createToken($data['device_name'] ?? 'pos', ['*'], now()->addDays(30));
        $this->audit->log('auth.login', $user, ['device' => $data['device_name'] ?? 'pos'], actorId: $user->id);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toAtomString(),
            'user' => $this->presentUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->audit->log('auth.logout', $request->user());

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        // ข้าม tenant โดยตั้งใจ: แสดงเฉพาะ membership ของ user คนนี้
        $memberships = TenantMembership::query()->withoutGlobalScope('tenant')->where('user_id', $user->id)->where('status', 'active')
            ->with('tenant')->get();
        $stores = StoreMembership::query()->withoutGlobalScope('tenant')->where('store_memberships.user_id', $user->id)->where('store_memberships.status', 'active')
            ->join('stores', 'stores.id', '=', 'store_memberships.store_id')
            ->get(['store_memberships.tenant_id', 'stores.id', 'stores.name', 'stores.code', 'stores.status'])
            ->groupBy('tenant_id');

        return response()->json([
            'user' => $this->presentUser($user),
            'tenants' => $memberships->map(fn ($m) => [
                'id' => $m->tenant->id,
                'name' => $m->tenant->name,
                'slug' => $m->tenant->slug,
                'status' => $m->tenant->status,
                'stores' => ($stores[$m->tenant_id] ?? collect())->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'status' => $s->status,
                ])->values(),
            ])->values(),
        ]);
    }

    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_platform_admin' => $user->is_platform_admin,
        ];
    }
}
