<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CounterOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:100000', 'max:10000000000'],
            'delivery_days' => ['nullable', 'integer', 'min:1', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
