<?php

namespace App\Modules\Ordering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddItemsRequest extends FormRequest
{
    public function rules(): array
    {
        return OrderItemRules::rules('items', required: true);
    }
}
