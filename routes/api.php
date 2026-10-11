<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FabricController;
use App\Http\Controllers\Api\V1\JobRequestController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\TailorController;
use Illuminate\Support\Facades\Route;

/*
| Versioned API shared by the web SPA and the future iOS/Android apps.
| Breaking changes go in /api/v2; v1 stays stable for shipped mobile builds.
*/

Route::prefix('v1')->name('v1.')->group(function () {

    // Public
    Route::get('meta', MetaController::class)->name('meta');
    Route::get('fabrics', [FabricController::class, 'index'])->name('fabrics.index');
    Route::get('fabrics/{slug}', [FabricController::class, 'show'])->name('fabrics.show');
    Route::get('tailors', [TailorController::class, 'index'])->name('tailors.index');
    Route::get('tailors/{slug}', [TailorController::class, 'show'])->name('tailors.show');

    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    // Authenticated (cookie session for the SPA, bearer token for mobile)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::put('me/tailor-profile', [TailorController::class, 'upsertMine'])->name('me.tailor-profile');

        // Customer: requests
        Route::get('job-requests', [JobRequestController::class, 'index'])->name('job-requests.index');
        Route::post('job-requests', [JobRequestController::class, 'store'])->middleware('throttle:marketplace-writes')->name('job-requests.store');
        Route::get('job-requests/{jobRequest}', [JobRequestController::class, 'show'])->name('job-requests.show');
        Route::post('job-requests/{jobRequest}/cancel', [JobRequestController::class, 'cancel'])->name('job-requests.cancel');
        Route::get('job-requests/{jobRequest}/matches', [JobRequestController::class, 'matches'])->name('job-requests.matches');

        // Tailor: inbox and offers
        Route::get('tailor/inbox', [JobRequestController::class, 'inbox'])->name('tailor.inbox');
        Route::get('tailor/offers', [OfferController::class, 'mine'])->name('tailor.offers');
        Route::post('job-requests/{jobRequest}/offers', [OfferController::class, 'store'])->middleware('throttle:marketplace-writes')->name('offers.store');

        // Negotiation
        Route::middleware('throttle:marketplace-writes')->group(function () {
            Route::post('offers/{offer}/counter', [OfferController::class, 'counter'])->name('offers.counter');
            Route::post('offers/{offer}/revise', [OfferController::class, 'revise'])->name('offers.revise');
            Route::post('offers/{offer}/withdraw', [OfferController::class, 'withdraw'])->name('offers.withdraw');
            Route::post('offers/{offer}/decline', [OfferController::class, 'decline'])->name('offers.decline');
            Route::post('offers/{offer}/accept', [OfferController::class, 'accept'])->name('offers.accept');
        });

        // Orders
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/transitions', [OrderController::class, 'transition'])->name('orders.transition');
    });
});
