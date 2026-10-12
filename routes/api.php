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
use App\Http\Controllers\Api\V1\FabricOrderController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use App\Http\Controllers\Api\V1\PriceOfferController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\VendorFabricController;
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

    // Paystack server-to-server callback. No session or CSRF: it is authenticated by its HMAC signature.
    Route::post('webhooks/paystack', PaystackWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.paystack');

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


        // Fabric shop: inDrive-style price offers
        Route::get('price-offers', [PriceOfferController::class, 'mine'])->name('price-offers.mine');
        Route::get('price-offers/{priceOffer}', [PriceOfferController::class, 'show'])->name('price-offers.show');
        Route::get('vendor/price-offers', [PriceOfferController::class, 'vendor'])->name('vendor.price-offers');
        Route::middleware('throttle:marketplace-writes')->group(function () {
            Route::post('fabrics/{slug}/price-offers', [PriceOfferController::class, 'store'])->name('price-offers.store');
            Route::post('price-offers/{priceOffer}/accept', [PriceOfferController::class, 'accept'])->name('price-offers.accept');
            Route::post('price-offers/{priceOffer}/counter', [PriceOfferController::class, 'counter'])->name('price-offers.counter');
            Route::post('price-offers/{priceOffer}/decline', [PriceOfferController::class, 'decline'])->name('price-offers.decline');
            Route::post('price-offers/{priceOffer}/withdraw', [PriceOfferController::class, 'withdraw'])->name('price-offers.withdraw');
        });

        // Fabric shop: cart, checkout and orders
        Route::post('shop/quote', [ShopController::class, 'quote'])->middleware('throttle:shop-quotes')->name('shop.quote');
        Route::post('checkouts', [ShopController::class, 'store'])->middleware('throttle:marketplace-writes')->name('checkouts.store');
        Route::get('checkouts/{checkout}', [ShopController::class, 'show'])->name('checkouts.show');
        Route::post('checkouts/{checkout}/verify', [ShopController::class, 'verify'])->middleware('throttle:shop-quotes')->name('checkouts.verify');
        Route::post('checkouts/{checkout}/cancel', [ShopController::class, 'cancel'])->name('checkouts.cancel');
        Route::get('fabric-orders', [FabricOrderController::class, 'index'])->name('fabric-orders.index');
        Route::get('fabric-orders/{fabricOrder}', [FabricOrderController::class, 'show'])->name('fabric-orders.show');
        Route::post('fabric-orders/{fabricOrder}/transitions', [FabricOrderController::class, 'transition'])->middleware('throttle:marketplace-writes')->name('fabric-orders.transition');

        // Vendor workspace
        Route::get('vendor/fabric-orders', [FabricOrderController::class, 'vendor'])->name('vendor.fabric-orders');
        Route::get('vendor/fabrics', [VendorFabricController::class, 'index'])->name('vendor.fabrics');
        Route::put('me/vendor-profile', [VendorFabricController::class, 'upsertProfile'])->middleware('throttle:marketplace-writes')->name('me.vendor-profile');
        Route::post('vendor/fabrics', [VendorFabricController::class, 'store'])->middleware('throttle:marketplace-writes')->name('vendor.fabrics.store');
        Route::patch('vendor/fabrics/{fabric}', [VendorFabricController::class, 'update'])->middleware('throttle:marketplace-writes')->name('vendor.fabrics.update');

        // Orders
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/transitions', [OrderController::class, 'transition'])->middleware('throttle:marketplace-writes')->name('orders.transition');
    });
});
