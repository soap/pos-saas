<?php

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitSlipRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // ข้อความใน QR บนสลิปโอนเงิน (สแกนจากสลิปของลูกค้า)
            'slip_payload' => ['required', 'string', 'max:128'],
        ];
    }
}
