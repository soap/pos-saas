<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\PaymentAccount;
use App\Modules\Payments\Services\PromptPayPayload;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * สร้าง (POST) หรือแก้ไข (PATCH) บัญชีรับชำระ QR — ช่อง credentials/settings ที่ต้องมีมาจาก config pos.payments.providers.{code}
 * PATCH: ส่ง credentials มาเฉพาะเมื่อต้องการเปลี่ยน (ต้องส่งครบทุกช่อง)
 */
class PaymentAccountRequest extends FormRequest
{
    public const PROVIDERS = ['omise', 'easyslip'];

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $provider = $creating
            ? (string) $this->input('provider_code')
            : (string) PaymentAccount::query()->find($this->route('id'))?->provider_code;
        $config = in_array($provider, self::PROVIDERS, true) ? config("pos.payments.providers.{$provider}") : ['credentials' => [], 'settings' => []];

        $rules = [
            'provider_code' => $creating ? ['required', Rule::in(self::PROVIDERS)] : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'credentials' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', $config['credentials'])],
            'settings' => [$creating && $config['settings'] ? 'required' : 'sometimes', 'array:'.implode(',', $config['settings'])],
        ];
        foreach ($config['credentials'] as $key) {
            $rules["credentials.{$key}"] = ['required_with:credentials', 'string', 'max:255'];
        }
        foreach ($config['settings'] as $key) {
            $rules["settings.{$key}"] = ['required_with:settings', 'string', 'max:64'];
        }
        if ($provider === 'easyslip') {
            $rules['settings.promptpay_id'][] = function (string $attribute, mixed $value, Closure $fail): void {
                if (! PromptPayPayload::isValidId((string) $value)) {
                    $fail('PromptPay ID ต้องเป็นเบอร์มือถือ 10 หลักหรือเลข 13 หลัก');
                }
            };
        }
        if ($provider === 'omise') {
            $rules['credentials.secret_key'][] = 'starts_with:skey_';
            $rules['settings.public_key'][] = 'starts_with:pkey_';
        }

        return $rules;
    }
}
