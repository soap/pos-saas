<?php

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount_satang' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // QR ที่ provider คืนเงินอัตโนมัติไม่ได้: ร้านโอนคืนเองแล้วบันทึกด้วย manual_transfer
            'refund_method' => ['nullable', 'in:manual_transfer'],
            'transfer_reference' => ['nullable', 'string', 'max:128'],
        ];
    }
}
