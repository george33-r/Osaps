<?php

namespace App\Http\Requests;

use App\Domain\Orders\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(OrderStatus::states())],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
