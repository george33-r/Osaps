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
