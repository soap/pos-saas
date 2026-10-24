<?php

namespace App\Modules\Ordering\Http\Requests;

final class OrderItemRules
{
    public static function rules(string $key, bool $required): array
    {
        return [
            $key => [$required ? 'required' : 'nullable', 'array', 'max:100'],
            "{$key}.*.product_id" => ['required', 'uuid'],
            "{$key}.*.quantity" => ['required', 'integer', 'min:1', 'max:999'],
            "{$key}.*.modifier_option_ids" => ['nullable', 'array', 'max:20'],
            "{$key}.*.modifier_option_ids.*" => ['uuid'],
            "{$key}.*.notes" => ['nullable', 'string', 'max:255'],
        ];
    }
}
