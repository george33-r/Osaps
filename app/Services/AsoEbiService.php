<?php

namespace App\Services;

use App\Domain\AsoEbi\AsoEbiRules;
use App\Domain\AsoEbi\AsoEbiStatus;
use App\Domain\Shop\PriceOfferStatus;
use App\Domain\Support\DomainRuleViolation;
use App\Models\AsoEbiGroup;
use App\Models\Fabric;
use App\Models\PriceOffer;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Creating and running aṣọ-ẹbí groups. Guests' payments go through CheckoutService::createForShare. */
class AsoEbiService
{
    public function __construct(private readonly AsoEbiRules $rules, private readonly Notifier $notifier) {}

    /** @param array<string,mixed> $data validated input */
    public function create(User $organizer, Fabric $fabric, array $data): AsoEbiGroup
    {
        return DB::transaction(function () use ($organizer, $fabric, $data) {
            $fabric = Fabric::query()->with('vendor')->lockForUpdate()->findOrFail($fabric->id);
            $now = new DateTimeImmutable;
            $deadline = new DateTimeImmutable((string) $data['deadline_at']);
            $this->rules->assertCanCreate(
                yardsPerGuest: (int) $data['yards_per_guest'],
                minOrderQuantity: (int) $fabric->min_order_quantity,
                targetGuests: (int) $data['target_guests'],
                deadline: $deadline,
                eventDate: new DateTimeImmutable((string) $data['event_date']),
                now: $now,
                fabricListed: $fabric->status === 'active',
                fulfilment: (string) $data['fulfilment'],
            );

            $group = new AsoEbiGroup(['title' => $data['title'], 'message' => $data['message'] ?? null]);
            $group->code = $this->newCode();
            $group->organizer_id = $organizer->id;
            $group->fabric_id = $fabric->id;
            $group->event_date = $data['event_date'];
            $group->deadline_at = $deadline->format('Y-m-d H:i:s');
            $group->yards_per_guest = (int) $data['yards_per_guest'];
            $group->target_guests = (int) $data['target_guests'];
            $group->fulfilment = $data['fulfilment'];
            $group->organizer_phone = $data['organizer_phone'];
            $group->status = AsoEbiStatus::OPEN;

            // A bulk price the organiser agreed with this vendor travels with the group.
            if (! empty($data['price_offer_id'])) {
                $offer = PriceOffer::query()->lockForUpdate()->find((int) $data['price_offer_id']);
                if ($offer === null || $offer->buyer_id !== $organizer->id || $offer->fabric_id !== $fabric->id || ! $offer->isUsable()) {
                    throw new DomainRuleViolation('That agreed price cannot be used for this group. It may have expired.', 'offer_not_usable');
                }
                $group->deal_unit_minor = min($offer->unit_minor, (int) $fabric->price_minor);
                $group->deal_quantity = $offer->quantity;
                $group->price_offer_id = $offer->id;
                $offer->status = PriceOfferStatus::USED;
                $offer->used_at = now();
                $offer->save();
            }
            $group->save();

            $this->notifier->notify($fabric->vendor?->user_id, 'aso_ebi_started', "Aṣọ-ẹbí started on {$fabric->name}",
                "{$group->target_guests} guests × {$group->yards_per_guest} {$fabric->unit}s are being invited for {$group->title}. Keep stock ready.", '/vendor?tab=orders');

            return $group;
        });
    }

    public function close(User $organizer, AsoEbiGroup $group): AsoEbiGroup
    {
        return $this->move($organizer, $group, AsoEbiStatus::CLOSED);
    }

    public function reopen(User $organizer, AsoEbiGroup $group): AsoEbiGroup
    {
        if ($group->deadline_at->isPast()) {
            throw new DomainRuleViolation('The deadline has passed. Start a new group to keep collecting.', 'deadline_past');
        }

        return $this->move($organizer, $group, AsoEbiStatus::OPEN);
    }

    public function cancel(User $organizer, AsoEbiGroup $group): AsoEbiGroup
    {
        $this->rules->assertCanCancel($group->shares()->where('status', 'paid')->count());

        return $this->move($organizer, $group, AsoEbiStatus::CANCELLED);
    }

    /** Close groups whose deadline has passed and tell the organiser. */
    public function closeExpired(): int
    {
        $n = 0;
        foreach (AsoEbiGroup::query()->where('status', AsoEbiStatus::OPEN)->where('deadline_at', '<', now())->limit(100)->get() as $g) {
            $g->status = AsoEbiStatus::CLOSED;
            $g->save();
            $paid = $g->shares()->where('status', 'paid')->count();
            $this->notifier->notify($g->organizer_id, 'aso_ebi_closed', "{$g->title}: payments closed", "{$paid} of {$g->target_guests} guests paid.", '/aso-ebi/'.$g->code);
            $n++;
        }

        return $n;
    }

    private function move(User $organizer, AsoEbiGroup $group, string $to): AsoEbiGroup
    {
        return DB::transaction(function () use ($organizer, $group, $to) {
            $group = AsoEbiGroup::query()->lockForUpdate()->findOrFail($group->id);
            $role = $organizer->isAdmin() ? 'admin' : ($group->organizer_id === $organizer->id ? 'organizer' : null);
            if ($role === null) {
                throw new DomainRuleViolation('Only the organiser can change this group.', 'forbidden');
            }
            AsoEbiStatus::assert($group->status, $to, $role);
            $group->status = $to;
            $group->save();

            return $group;
        });
    }

    private function newCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (AsoEbiGroup::query()->where('code', $code)->exists());

        return $code;
    }
}
