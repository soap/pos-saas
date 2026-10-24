<?php

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'method' => ['required', 'in:cash,qr'],
            // ไม่ระบุ = ชำระยอดค้างทั้งหมด (server คำนวณ)
            'amount_satang' => ['nullable', 'integer', 'min:1'],
            'tendered_satang' => ['nullable', 'integer', 'min:1', 'prohibited_unless:method,cash'],
        ];
    }
}
