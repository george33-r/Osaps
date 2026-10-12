# Fabric shop: buy now, name your price, Paystack checkout

## What shoppers can do
- **Buy now**: choose the quantity, add to cart, pay.
- **Name your price** (inDrive-style): offer a price per unit below the listing. The vendor accepts, counters or declines; the buyer can accept a counter, raise once more, or decline. Each side gets 3 counters. Someone must answer within 24 hours. An agreed price is held for the buyer for 48 hours.
  - Offers below 70% of the list price are refused, and a vendor can never counter above their own listing.
  - An agreed price covers up to the offered quantity, for that buyer only.
- **Checkout**: delivery (flat fee per vendor) or free pickup at the vendor's market. One payment through Paystack covers every vendor; each vendor gets its own order.
- **Track**: Paid → Packed → Out for delivery / Ready for pickup → Received → Completed.

## What vendors can do (`/vendor`)
- Answer price offers (badge shows how many are waiting).
- See orders **only after they are paid**, with the buyer's name, phone and address; mark packed and dispatched.
- Change price and stock on their listings (stock cannot go below what buyers are paying for right now), and add new listings.

## How money and stock are protected
- Prices always come from the database. The browser sends only fabric IDs, quantities and agreed-offer IDs.
- The buyer sends the total they saw; if anything changed, checkout stops with `cart_changed` and shows the new total.
- Stock is reserved for 60 minutes while the buyer pays, then released.
- A checkout becomes **paid** only when Paystack says so: either the signed webhook (HMAC-SHA512 with your secret key) or a direct verify call to Paystack when the buyer returns. Both are idempotent; replays are logged once and ignored.
- The amount, currency and reference must all match. Underpayments are refused.
- If money arrives after the stock hold ended and the fabric has sold out, the checkout is marked **needs_refund** and the orders are cancelled with that reason. Refund it from your Paystack dashboard.

## Switching on Paystack
1. Sign up at paystack.com and complete business verification (needed for live payments).
2. Dashboard → **Settings → API Keys & Webhooks**:
   - Copy the **Test Secret Key** (`sk_test_...`).
   - Set **Test Webhook URL** to `https://shoposaps.com/api/v1/webhooks/paystack`.
3. In the server `.env` add: `PAYSTACK_SECRET_KEY=sk_test_...`
4. Buy something on the site and pay with a Paystack test card (Paystack docs → "Test payments").
5. When everything works, set the **Live Webhook URL** to the same address and swap in `sk_live_...`.

Never put the secret key in the website code or share it in chat. Without a key, the shop falls back to "contact the vendor".

## Optional: cron
The shop releases stale stock and expires old offers whenever someone uses it, so it works without cron.
For tidier timing, add a Hostinger cron job every 5 minutes:
`/usr/bin/php /home/USER/domains/shoposaps.com/public_html/artisan schedule:run`

## Not done yet
- Vendor payouts are recorded (`vendor_commission_minor`) but paid out manually; Paystack Split/Subaccounts is the next step.
- Refunds are made from the Paystack dashboard; there is no refund button in OSAPS yet.
- Vendor photo uploads.
- The Laravel side of the shop has not been run in the build environment (Composer was blocked). `tests/Feature/Api/ShopFlowTest.php` (14 tests) should be run once Composer is available. The shop rules themselves are covered by `tests/Unit/Domain/ShopRulesTest.php`, which passes.
