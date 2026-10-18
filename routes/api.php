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
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\AsoEbiController;
use App\Http\Controllers\Api\V1\FabricOrderController;
use App\Http\Controllers\Api\V1\LiveController;
use App\Http\Controllers\Api\V1\TryOnController;
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

    // Public: aṣọ-ẹbí share links and the live market.
    Route::get('aso-ebi/{asoEbiGroup}', [AsoEbiController::class, 'show'])->where('asoEbiGroup', '[a-hj-km-np-z2-9]{10}')->name('aso-ebi.show');
    Route::get('live', [LiveController::class, 'index'])->name('live.index');

    // Cart prices: open to everyone, so shoppers see the full total before making an account.
    Route::post('shop/quote', [ShopController::class, 'quote'])->middleware('throttle:shop-quotes')->name('shop.quote');

    // Paystack server-to-server callback. No session or CSRF: it is authenticated by its HMAC signature.
    Route::post('webhooks/paystack', PaystackWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.paystack');

    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
        // Checkout without a sign-up form: name, phone and email make the account in one step.
        Route::post('auth/quick', [AuthController::class, 'quick'])->name('auth.quick');
        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot');
        Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset');
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

        // Sample design photos on a request, and photos in the chat (served only to the people involved)
        Route::post('job-requests/{jobRequest}/photos', [\App\Http\Controllers\Api\V1\DesignPhotoController::class, 'store'])->middleware('throttle:marketplace-writes')->name('job-requests.photos.store');
        Route::delete('job-requests/{jobRequest}/photos/{photo}', [\App\Http\Controllers\Api\V1\DesignPhotoController::class, 'destroy'])->whereNumber('photo')->name('job-requests.photos.destroy');
        Route::get('design-photos/{photo}', [\App\Http\Controllers\Api\V1\DesignPhotoController::class, 'show'])->whereNumber('photo')->name('design-photos.show');
        Route::get('chat-photos/{message}', [\App\Http\Controllers\Api\V1\DesignPhotoController::class, 'message'])->whereNumber('message')->name('chat-photos.show');

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
        Route::post('checkouts', [ShopController::class, 'store'])->middleware('throttle:marketplace-writes')->name('checkouts.store');
        Route::get('checkouts/{checkout}', [ShopController::class, 'show'])->name('checkouts.show');
        Route::post('checkouts/{checkout}/verify', [ShopController::class, 'verify'])->middleware('throttle:shop-quotes')->name('checkouts.verify');
        Route::post('checkouts/{checkout}/cancel', [ShopController::class, 'cancel'])->name('checkouts.cancel');
        Route::get('fabric-orders', [FabricOrderController::class, 'index'])->name('fabric-orders.index');
        Route::get('fabric-orders/{fabricOrder}', [FabricOrderController::class, 'show'])->name('fabric-orders.show');
        Route::post('fabric-orders/{fabricOrder}/transitions', [FabricOrderController::class, 'transition'])->middleware('throttle:marketplace-writes')->name('fabric-orders.transition');
        Route::post('fabric-orders/{fabricOrder}/dispute', [FabricOrderController::class, 'dispute'])->middleware('throttle:marketplace-writes')->name('fabric-orders.dispute');

        // Cart saved to the account, so it follows the shopper between devices
        Route::get('cart', [CartController::class, 'show'])->name('cart.show');
        Route::put('cart', [CartController::class, 'replace'])->middleware('throttle:shop-quotes')->name('cart.replace');

        // Aṣọ-ẹbí group orders
        Route::get('aso-ebi', [AsoEbiController::class, 'mine'])->name('aso-ebi.mine');
        Route::post('fabrics/{slug}/aso-ebi', [AsoEbiController::class, 'store'])->middleware('throttle:marketplace-writes')->name('aso-ebi.store');
        Route::middleware('throttle:marketplace-writes')->where(['asoEbiGroup' => '[a-hj-km-np-z2-9]{10}'])->group(function () {
            Route::post('aso-ebi/{asoEbiGroup}/quote', [AsoEbiController::class, 'quote'])->withoutMiddleware('throttle:marketplace-writes')->middleware('throttle:shop-quotes')->name('aso-ebi.quote');
            Route::post('aso-ebi/{asoEbiGroup}/join', [AsoEbiController::class, 'join'])->name('aso-ebi.join');
            Route::post('aso-ebi/{asoEbiGroup}/close', [AsoEbiController::class, 'close'])->name('aso-ebi.close');
            Route::post('aso-ebi/{asoEbiGroup}/reopen', [AsoEbiController::class, 'reopen'])->name('aso-ebi.reopen');
            Route::post('aso-ebi/{asoEbiGroup}/cancel', [AsoEbiController::class, 'cancel'])->name('aso-ebi.cancel');
        });

        // Account, notifications, measurements
        Route::put('me', [AccountController::class, 'update'])->middleware('throttle:marketplace-writes')->name('me.update');
        Route::get('notifications', [AccountController::class, 'notifications'])->name('notifications.index');
        Route::post('notifications/read', [AccountController::class, 'markRead'])->name('notifications.read');
        Route::get('me/measurements', [AccountController::class, 'measurements'])->name('me.measurements');
        Route::put('me/measurements', [AccountController::class, 'saveMeasurements'])->middleware('throttle:marketplace-writes')->name('me.measurements.save');

        // Try-on (AI image), off until a provider key is set
        Route::post('ai/try-on', TryOnController::class)->middleware('throttle:ai')->name('ai.try-on');

        // Back office (admins only): shops, problem reports, refunds, two-person payouts, stuck orders, log.
        Route::middleware(['admin', 'throttle:marketplace-writes'])->prefix('admin')->name('admin.')->group(function () {
            Route::get('overview', [AdminController::class, 'overview'])->withoutMiddleware('throttle:marketplace-writes')->name('overview');
            Route::get('vendors', [AdminController::class, 'vendors'])->withoutMiddleware('throttle:marketplace-writes')->name('vendors');
            Route::post('vendors/{fabricVendor}/review', [AdminController::class, 'reviewVendor'])->whereNumber('fabricVendor')->name('vendors.review');
            Route::get('disputes', [AdminController::class, 'disputes'])->withoutMiddleware('throttle:marketplace-writes')->name('disputes');
            Route::post('fabric-orders/{fabricOrder}/resolve', [AdminController::class, 'resolveDispute'])->name('disputes.resolve');
            Route::get('refunds', [AdminController::class, 'refunds'])->withoutMiddleware('throttle:marketplace-writes')->name('refunds');
            Route::post('fabric-orders/{fabricOrder}/refund', [AdminController::class, 'refund'])->name('refunds.store');
            Route::post('refunds/{refund}/mark-failed', [AdminController::class, 'markRefundFailed'])->whereNumber('refund')->name('refunds.failed');
            Route::get('payouts', [AdminController::class, 'payouts'])->withoutMiddleware('throttle:marketplace-writes')->name('payouts');
            Route::post('fabric-orders/{fabricOrder}/payout-approve', [AdminController::class, 'approvePayout'])->name('payouts.approve');
            Route::post('fabric-orders/{fabricOrder}/paid-out', [AdminController::class, 'markPaid'])->name('payouts.paid');
            Route::get('stuck', [AdminController::class, 'stuck'])->withoutMiddleware('throttle:marketplace-writes')->name('stuck');
            Route::get('actions', [AdminController::class, 'actions'])->withoutMiddleware('throttle:marketplace-writes')->name('actions');
        });

        // Vendor workspace
        Route::get('vendor/fabric-orders', [FabricOrderController::class, 'vendor'])->name('vendor.fabric-orders');
        Route::get('vendor/fabrics', [VendorFabricController::class, 'index'])->name('vendor.fabrics');
        Route::get('me/vendor-profile', [VendorFabricController::class, 'profile'])->name('me.vendor-profile.show');
        Route::post('vendor/fabrics/{fabric}/media', [VendorFabricController::class, 'addMedia'])->middleware('throttle:marketplace-writes')->name('vendor.fabrics.media.store');
        Route::delete('vendor/fabrics/{fabric}/media/{mediaId}', [VendorFabricController::class, 'removeMedia'])->whereNumber('mediaId')->name('vendor.fabrics.media.destroy');
        Route::get('vendor/live', [LiveController::class, 'mine'])->name('vendor.live');
        Route::post('vendor/live', [LiveController::class, 'store'])->middleware('throttle:marketplace-writes')->name('vendor.live.store');
        Route::delete('vendor/live/{liveSession}', [LiveController::class, 'destroy'])->name('vendor.live.destroy');
        Route::put('me/vendor-profile', [VendorFabricController::class, 'upsertProfile'])->middleware('throttle:marketplace-writes')->name('me.vendor-profile');
        Route::post('vendor/fabrics', [VendorFabricController::class, 'store'])->middleware('throttle:marketplace-writes')->name('vendor.fabrics.store');
        Route::patch('vendor/fabrics/{fabric}', [VendorFabricController::class, 'update'])->middleware('throttle:marketplace-writes')->name('vendor.fabrics.update');

        // Orders
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/transitions', [OrderController::class, 'transition'])->middleware('throttle:marketplace-writes')->name('orders.transition');
    });
});
