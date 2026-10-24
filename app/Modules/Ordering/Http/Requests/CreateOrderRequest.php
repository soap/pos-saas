<?php

namespace App\Modules\Ordering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * client ส่งได้เฉพาะ intent (product_id, quantity, modifier IDs, note) — ราคา/ยอดคำนวณที่ server
 * field อย่าง price/total/tenant_id/store_id ที่ส่งมาจะถูกละทิ้ง (ไม่อยู่ใน validated())
 */
class CreateOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'order_type' => ['required', 'in:dine_in,takeaway'],
            'table_label' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:500'],
            ...OrderItemRules::rules('items', required: false),
        ];
    }
}
