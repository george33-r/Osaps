<?php

use App\Services\NegotiationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('marketplace:expire-stale', function (NegotiationService $negotiation) {
    $result = $negotiation->expireStale();
    $this->info("Expired {$result['offers']} offers and {$result['requests']} requests.");
})->purpose('Expire offers and job requests whose windows have passed');

// Requires the scheduler: `* * * * * php artisan schedule:run` (see README).
Schedule::command('marketplace:expire-stale')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('shop:sweep', function (\App\Services\CheckoutService $checkouts, \App\Services\ShopOfferService $offers, \App\Services\AsoEbiService $groups) {
    $released = $checkouts->releaseExpired();
    $expired = $offers->expireStale();
    $completed = $checkouts->autoCompleteDelivered();
    $closed = $groups->closeExpired();
    $this->info("Released {$released} unpaid checkouts, expired {$expired} offers, completed {$completed} delivered orders, closed {$closed} aṣọ-ẹbí groups.");
})->purpose('Release stock held by unpaid checkouts and lapse unanswered price offers');

// The shop also sweeps lazily on every cart and offer request, so it works without cron too.
Schedule::command('shop:sweep')->everyFiveMinutes()->withoutOverlapping();
