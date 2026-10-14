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

// Sends queued WhatsApp updates. Shared hosting has no long-running worker, so cron starts a short
// one each minute that stops when the queue is empty (or after 50 seconds).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=1')->everyMinute()->withoutOverlapping();

// Heartbeat: the admin screen shows when cron last ran, so a missing cron job is noticed.
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever('scheduler:last_run', now()->toIso8601String()))->everyMinute()->name('heartbeat');
