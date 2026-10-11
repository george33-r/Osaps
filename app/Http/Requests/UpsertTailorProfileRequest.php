<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertTailorProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTailor() ?? false;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1500'],
            'city' => ['required', 'string', 'max:80'],
            'service_cities' => ['required', 'array', 'min:1', 'max:10'],
            'service_cities.*' => ['string', 'max:80'],
            'specialties' => ['required', 'array', 'min:1', 'max:8'],
            'specialties.*' => [Rule::in(array_keys(config('marketplace.garment_categories')))],
            'portfolio_tags' => ['nullable', 'array', 'max:12'],
            'portfolio_tags.*' => ['string', 'max:30'],
            'weekly_capacity' => ['required', 'integer', 'min:1', 'max:200'],
            'starting_price_minor' => ['nullable', 'integer', 'min:100000'],
            'accepting_work' => ['boolean'],
        ];
    }
}
