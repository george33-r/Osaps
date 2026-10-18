<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy check happens in the controller
    }

    public function rules(): array
    {
        $min = 100_000;           // ₦1,000
        $max = 100_000_000_00;    // ₦100m — sanity ceiling

        return [
            'title' => ['required', 'string', 'min:5', 'max:140'],
            'garment_category' => ['required', Rule::in(array_keys(config('marketplace.garment_categories')))],
            'occasion' => ['nullable', Rule::in(array_keys(config('marketplace.occasions')))],
            'description' => ['required', 'string', 'min:20', 'max:3000'],
            'style_tags' => ['nullable', 'array', 'max:8'],
            'style_tags.*' => ['string', 'max:30'],
            'mode' => ['required', Rule::in(['budget', 'open'])],
            'budget_max_minor' => ['nullable', 'required_if:mode,budget', 'integer', "min:{$min}", "max:{$max}"],
            // A minimum on its own is allowed; it only has to sit below the maximum when both are given.
            'budget_min_minor' => ['nullable', 'integer', "min:{$min}", Rule::when(fn ($in) => filled($in->budget_max_minor), ['lte:budget_max_minor'])],
            'fabric_status' => ['required', Rule::in(['own_fabric', 'buying_on_platform', 'need_advice'])],
            'fabric_id' => ['nullable', 'required_if:fabric_status,buying_on_platform', 'integer', 'exists:fabrics,id'],
            'city' => ['required', 'string', 'max:80'],
            'needed_by' => ['required', 'date', 'after:today', 'before:+1 year'],
            'fitting_required' => ['boolean'],
            'is_express' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'budget_max_minor.required_if' => 'Tell tailors your budget, or switch to open offers.',
            'fabric_id.required_if' => 'Choose the fabric you are buying, or pick another fabric option.',
            'needed_by.after' => 'Choose a date in the future.',
        ];
    }
}
