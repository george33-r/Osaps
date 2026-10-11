<?php

use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FabricController;
use App\Http\Controllers\Api\V1\JobRequestController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\OfferMessageController;
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

    // AI-assisted features (guests allowed, rate limited; each response says whether AI or rules produced it)
    Route::middleware('throttle:ai')->prefix('ai')->name('ai.')->group(function () {
        Route::get('status', [AiController::class, 'status'])->name('status');
        Route::get('fabric-search', [AiController::class, 'fabricSearch'])->name('fabric-search');
        Route::post('stylist', [AiController::class, 'stylist'])->name('stylist');
        Route::get('fabrics/{slug}/ideas', [AiController::class, 'fabricIdeas'])->name('fabric-ideas');
        Route::post('inspiration', [AiController::class, 'inspiration'])->middleware('auth:sanctum')->name('inspiration');
    });

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

        // Messages between the two parties to an offer
        Route::get('offers/{offer}/messages', [OfferMessageController::class, 'index'])->name('offers.messages.index');
        Route::post('offers/{offer}/messages', [OfferMessageController::class, 'store'])->middleware('throttle:marketplace-writes')->name('offers.messages.store');

        // Vendor contact (signed-in only)
        Route::get('fabrics/{slug}/contact', [FabricController::class, 'contact'])->name('fabrics.contact');

        // Orders
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/transitions', [OrderController::class, 'transition'])->middleware('throttle:marketplace-writes')->name('orders.transition');
    });
});
