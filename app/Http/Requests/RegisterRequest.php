<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            // Admin can never be self-assigned.
            'role' => ['required', Rule::in(User::SELF_SERVICE_ROLES)],
            'city' => ['nullable', 'string', 'max:80'],
            // Present for mobile clients that want a bearer token instead of a cookie session.
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
