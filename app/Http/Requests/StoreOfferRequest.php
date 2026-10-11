<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:100000', 'max:10000000000'],
            'delivery_days' => ['required', 'integer', 'min:1', 'max:120'],
            'includes_fitting' => ['nullable', 'boolean'],
            'alterations_included' => ['nullable', 'integer', 'min:0', 'max:10'],
            'delivery_fee_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
