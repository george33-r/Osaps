<?php

namespace App\Http\Resources;

use App\Domain\AsoEbi\AsoEbiRules;
use App\Http\Resources\Concerns\FormatsMoney;
use Illuminate\Http\Request;

/** Public view of an aṣọ-ẹbí group: what to buy, the price, and how many have paid. Pass ['viewer_is_organizer' => bool]. */
class AsoEbiGroupResource extends ApiResource
{
    use FormatsMoney;

    public function toArray(Request $request): array
    {
        $paid = $this->shares->where('status', 'paid');
        $committed = (int) $this->shares->whereIn('status', ['paid', 'pending'])->sum('yards');
        $rules = app(AsoEbiRules::class);
        $dealLeft = $this->deal_quantity !== null ? max(0, $this->deal_quantity - $committed) : null;
        $unit = $this->deal_unit_minor !== null && ($dealLeft ?? 0) >= $this->yards_per_guest
            ? min($this->deal_unit_minor, $this->fabric->price_minor)
            : $this->fabric->price_minor;
        $organizer = (bool) $this->extra('viewer_is_organizer');

        return [
            'code' => $this->code,
            'title' => $this->title,
            'message' => $this->message,
            'event_date' => $this->event_date?->toDateString(),
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'status' => $this->isOpen() ? 'open' : ($this->status === 'open' ? 'closed' : $this->status),
            'yards_per_guest' => $this->yards_per_guest,
            'target_guests' => $this->target_guests,
            'fulfilment' => $this->fulfilment,
            'unit_price' => self::money($unit),
            'list_unit_price' => self::money($this->fabric->price_minor),
            'deal' => $this->deal_unit_minor !== null ? ['unit_price' => self::money($this->deal_unit_minor), 'quantity' => $this->deal_quantity, 'left' => $dealLeft] : null,
            'share_price' => self::money($unit * $this->yards_per_guest),
            'progress' => $rules->progress($paid->count(), $this->target_guests),
            'yards_paid' => (int) $paid->sum('yards'),
            // First names only, and only for guests who chose to show theirs.
            'paid_names' => $paid->where('show_name', true)->take(40)->map(fn ($s) => strtok((string) $s->guest?->name, ' '))->filter()->values(),
            'organizer_name' => strtok((string) $this->organizer?->name, ' '),
            'is_organizer' => $organizer,
            'my_share' => $this->when($request->user('sanctum') !== null, function () use ($request) {
                $mine = $this->shares->where('guest_id', $request->user('sanctum')->id)->sortByDesc('id')->first();

                return $mine ? ['status' => $mine->status, 'yards' => $mine->yards] : null;
            }),
            'guests' => $this->when($organizer, fn () => $this->shares->whereIn('status', ['paid', 'pending'])->map(fn ($s) => [
                'name' => $s->guest?->name, 'yards' => $s->yards, 'status' => $s->status,
            ])->values()),
            'fabric' => (new FabricResource($this->fabric))->toArray($request),
        ];
    }
}
