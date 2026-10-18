<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CheckoutResource;
use App\Models\Checkout;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Fabric cart quotes and Paystack checkout. */
class ShopController extends Controller
{
    public function __construct(private readonly CheckoutService $checkouts) {}

    /** @return array<string,mixed> */
    private function cartRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.fabric_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.price_offer_id' => ['nullable', 'integer', 'min:1'],
            'fulfilment' => ['required', 'in:delivery,pickup,arrival,international'],
            'country' => ['nullable', 'required_if:fulfilment,international', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate($this->cartRules());
        // Works signed out too, so a first-time shopper sees the full price before making an account.
        $q = $this->checkouts->quote($request->user('sanctum'), $data['items'], $data['fulfilment'], $data['country'] ?? null, $data['state'] ?? $data['address']['state'] ?? null);

        return response()->json(['data' => self::present($q)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->cartRules() + [
            'expected_total_minor' => ['required', 'integer', 'min:0'],
            'contact.name' => ['required', 'string', 'min:2', 'max:80'],
            'contact.phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'address.line1' => ['required_unless:fulfilment,pickup', 'nullable', 'string', 'max:160'],
            'address.line2' => ['nullable', 'string', 'max:160'],
            'address.city' => ['required_unless:fulfilment,pickup', 'nullable', 'string', 'max:80'],
            'address.state' => ['required_if:fulfilment,delivery,arrival', 'nullable', 'string', 'max:80', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && \App\Domain\Shop\DeliveryZones::normalise((string) $value) === null) {
                    $fail('Choose your state from the list.');
                }
            }],
            'address.postcode' => ['required_if:fulfilment,international', 'nullable', 'string', 'max:16'],
            'address.landmark' => ['nullable', 'string', 'max:160'],
            'arrival_date' => ['required_if:fulfilment,arrival', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before:+180 days'],
        ]);
        $user = $request->user();
        if (isset($data['address']['state'])) {
            $data['address']['state'] = \App\Domain\Shop\DeliveryZones::normalise($data['address']['state']);
        }
        $checkout = $this->checkouts->create(
            buyer: $user,
            items: $data['items'],
            fulfilment: $data['fulfilment'],
            contact: ['name' => $data['contact']['name'], 'phone' => $data['contact']['phone'], 'email' => $user->email],
            address: $data['fulfilment'] !== 'pickup' ? array_filter($data['address'] ?? [], fn ($v) => $v !== null && $v !== '') : null,
            expectedTotalMinor: (int) $data['expected_total_minor'],
            callbackUrl: url('/checkout/complete'),
            country: $data['country'] ?? null,
            arrivalDate: $data['arrival_date'] ?? null,
        );

        return (new CheckoutResource($checkout->load('orders.items.fabric', 'orders.vendor', 'orders.checkout')))
            ->withExtra(['authorization_url' => $checkout->authorization_url])
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Checkout $checkout): CheckoutResource
    {
        abort_unless($checkout->customer_id === $request->user()->id, 403);

        return new CheckoutResource($checkout->load('orders.items.fabric', 'orders.vendor', 'orders.checkout'));
    }

    /** Called when the buyer returns from Paystack. Asks Paystack directly; the browser's word is not enough. */
    public function verify(Request $request, Checkout $checkout): CheckoutResource
    {
        abort_unless($checkout->customer_id === $request->user()->id, 403);
        $checkout = $this->checkouts->verify($checkout);

        return new CheckoutResource($checkout->load('orders.items.fabric', 'orders.vendor', 'orders.checkout'));
    }

    public function cancel(Request $request, Checkout $checkout): CheckoutResource
    {
        abort_unless($checkout->customer_id === $request->user()->id, 403);
        $checkout = $this->checkouts->cancel($checkout, 'customer', 'Cancelled by the buyer before paying.', $request->user());

        return new CheckoutResource($checkout->load('orders.items.fabric', 'orders.vendor', 'orders.checkout'));
    }

    /** @param array<string,mixed> $q */
    public static function present(array $q): array
    {
        $m = fn (int $minor) => \App\Domain\Money\Money::minor($minor)->toArray();

        return [
            'ok' => $q['ok'],
            'vendors' => array_map(fn ($v) => [
                'vendor_id' => $v['vendor_id'],
                'vendor_name' => $v['vendor_name'],
                'subtotal' => $m($v['subtotal']),
                'delivery' => $m($v['delivery']),
                'total' => $m($v['total']),
                'lines' => array_map(fn ($l) => [
                    'fabric_id' => $l['fabric_id'],
                    'name' => $l['name'],
                    'unit' => $l['unit'],
                    'quantity' => $l['quantity'],
                    'unit_price' => $m($l['unit_minor']),
                    'list_unit_price' => $m($l['list_unit_minor']),
                    'line_total' => $m($l['line_total_minor']),
                    'saving' => $m($l['saving_minor']),
                    'price_offer_id' => $l['price_offer_id'],
                ], $v['lines']),
            ], $q['vendors']),
            'subtotal' => $m($q['subtotal']),
            'delivery' => $m($q['delivery']),
            'service_fee' => $m($q['service_fee']),
            'total' => $m($q['total']),
            'blocking' => $q['blocking'],
            'notices' => $q['notices'],
        ];
    }
}
