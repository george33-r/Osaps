# Buyer protection and the back office

Added 14 Oct 2026 after three independent AI reviews of the audit brief all named the same
problems: a vendor could mark its own order received and get paid, there was no refund path,
anyone could sell without being checked, and payouts relied on one person.

## What changed

| Area | Rule now | Code |
| --- | --- | --- |
| Delivery proof | Each paid order gets a 6-digit delivery code only the buyer sees. The vendor can mark it received only by entering that code (5 wrong tries locks it). The buyer can also confirm in the app. | `app/Domain/Shop/DeliveryCode.php`, `CheckoutService::transition` |
| Buyer window | After a confirmed delivery the buyer has 7 days (`SHOP_AUTO_COMPLETE_DAYS`) to report a problem. Then the order completes and the payout becomes releasable. | `PayoutRules::shouldAutoComplete` |
| Problem reports | The buyer can report a problem any time from payment until completion. It pauses completion and the payout. An admin settles it: refund (order cancelled, Paystack refund) or release (order completes). | `DisputeRules`, `TrustService::openDispute/resolveDispute` |
| Refunds | Admin refunds a cancelled paid order through Paystack `POST /refund`. Never more than the order total, never after the vendor payout was approved or sent. If Paystack does not answer, the refund stays pending and is never retried automatically; an admin checks the Paystack dashboard and marks it failed before trying again. `refund.processed` / `refund.failed` webhooks update it. | `RefundRules`, `TrustService::refund`, `PaystackWebhookController` |
| Vendor checks | New shops are hidden from buyers until an admin verifies them. A complete profile is sent for review automatically. Changing bank details on a verified shop sends it back for review (stops a stolen login quietly redirecting payouts). Admins can reject or suspend with a note. | `VendorVerification`, `Fabric::scopeListed`, `VendorFabricController::upsertProfile` |
| Payouts | Two different admins: one approves (shop must be verified, bank details present, no open problem), another records the bank transfer reference. `SHOP_TWO_PERSON_PAYOUTS=false` allows one admin while you only have one. | `PayoutRules::assertCanApprove/assertCanMarkPaid` |
| Admin log | Every admin decision is written to `admin_actions` (who, what, which record, note). | `AdminAction::record` |
| Back office | `/admin` page: overview and system checks (Paystack, cron, queue, email), shops to verify, problems, refunds, payouts, stuck orders (no handover for 7 days), admin log. | `AdminController`, `web/src/pages/Admin.tsx` |
| Security headers | CSP (own scripts only), X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS on HTTPS in production. Root `.htaccess` also 404s the app folders if rewriting ever stops. | `SecurityHeaders` middleware, `deploy/hostinger/root.htaccess` |
| Password reset | Forgot/reset by email, same answer whether or not the email exists, link valid 60 minutes, signs out mobile tokens. Needs `MAIL_*` in `.env`. | `AuthController::forgotPassword/resetPassword` |
| Saved cart | Signed-in carts are saved to the account and merged on sign-in; cleared from the browser on sign-out. | `CartController`, `web/src/lib/cart.tsx` |
| WhatsApp | Sent from the queue, not inside the page request. Cron starts a short worker every minute. With `QUEUE_CONNECTION=sync` it sends inline as before. | `App\Jobs\SendWhatsApp`, `routes/console.php` |
| Cron check | The scheduler writes a heartbeat every minute; the admin overview shows when it last ran. | `routes/console.php` |
| Tests on GitHub | `.github/workflows/tests.yml` runs the domain tests, the Laravel feature tests on MySQL and SQLite, the web build, and builds a `vendor` zip for Hostinger. | |

## Still true (not fixed by this change)

- The Laravel code has still never run. The GitHub workflow is how it gets run: push the code to a GitHub repository and read the result.
- Paystack refunds and the refund webhook payload shape are written from Paystack's docs and untested.
- Vendor verification is a manual check by an admin (phone call, account name match). There is no BVN, NIN or CAC lookup.
- No courier integration or tracking numbers; the delivery code is the proof of handover.
- Cancelling a paid order does not put the stock back; the vendor adjusts stock.
- No email verification on sign-up; WhatsApp numbers are not verified.
