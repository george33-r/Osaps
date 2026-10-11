# Implementation checklist

Status as of 11 Oct 2026. "Verified" means it was executed in this environment, not just written.

**Environment limit:** Packagist was blocked in the build environment, so `composer install` could not run.
The Laravel layer is complete code but has **never been booted**. Run the steps under "First run on your machine" before trusting it.

## Verification legend

- ✅ **Verified** — executed here and passed
- 🟡 **Written, not run** — code complete; needs `composer install` to execute
- ⬜ **Not started**

## Done in this slice: request → offers → negotiation → order

| Area | Status | Evidence |
|---|---|---|
| Domain rules: money in kobo, fees, offer rules, job/offer/order state machines, matching scorer, quote comparison (framework-free) | ✅ | 37 tests pass via `php tests/bin/run-domain-tests.php`; a deliberately injected bug was caught |
| PHP syntax for all 81 PHP files | ✅ | `php -l` clean |
| All internal class references resolve to files | ✅ | 62 references checked |
| Migrations: users, vendors, fabrics, requests, offers (+ append-only revisions), orders (+ append-only events) | 🟡 | Unique keys block double awards at DB level |
| API v1: auth (cookie for web, tokens for mobile), catalogue, tailors, requests, offers, counteroffers, accept, orders, transitions | 🟡 | 19 feature tests written in `tests/Feature/Api` |
| Authorization: policies, ownership checks, admin never self-assignable, tailors see customer first name only | 🟡 | Covered by feature tests |
| Idempotent offer acceptance with row locks | 🟡 | Feature test `test_accept_is_idempotent_and_single_award` |
| Offer/request expiry sweep (scheduler, every 5 min) | 🟡 | `marketplace:expire-stale` |
| Rate limiting (auth, marketplace writes, general API) | 🟡 | |
| Local demo seeder (flagged `is_demo`, no fake reviews, history or verified badges) | 🟡 | |
| Design system: tokens, type scale, buttons, fields, badges, dialogs, toasts, empty/error/skeleton states | ✅ | TypeScript check + production build pass |
| Procedural textile swatches (labelled as illustrations) | ✅ | Screenshots |
| Pages: home, fabric catalogue + detail, tailor directory + profile, sign in/register, post request, my requests, offer comparison, tailor inbox, tailor offer/negotiation, tailor profile onboarding, orders + order timeline | ✅ in preview mode | 18 screenshots at 390px and 1440px; no console errors; no horizontal overflow |
| Full journey clicked through in a browser (14 steps, both roles) | ✅ in preview mode | Playwright run: filters, validation, estimate, post, counter, tailor reply, explanation rule, accept, award, payout, transitions, dispute reason rule, completion |
| Production bundle excludes the design-review adapter | ✅ | Bundle grep |
| End-to-end against the real Laravel API | 🟡 | Not possible here — first run on your machine |

"Preview mode" means the frontend running against an in-memory adapter (`npm run preview:fixtures`) that mirrors the API contract. It proves the UI works, not that Laravel does.

## Bugs found and fixed during verification

- A production build shipped an unused preview chunk → guards made static; bundle now clean.
- "Lowest total" badge shown when delivery cost was unknown → renamed "Lowest price".
- Every offer dialog shared `id="dialog-title"` (broken `aria-labelledby`) → unique IDs.
- "How it works" nav link showed as active on the homepage.
- A customer's counteroffer was displayed as the headline price without a label.
- Browser-native validation hid the API's dispute-reason message.
- Strict-mode Eloquent would have thrown on registration (non-fillable `role`) and in the seeder → fixed before running.

## Remaining work (in recommended order)

| Phase | Item | Status |
|---|---|---|
| 1 | Run `composer install`, migrations, feature tests; fix anything they surface | ⬜ |
| 3 | Fabric vendor workspace: listings, stock, photo upload, stock reservation | ⬜ |
| 3 | Image uploads: inspiration photos on requests, tailor portfolio (private disk, type/size checks, signed URLs) | ⬜ |
| 3 | Messaging between customer and tailor; clarification questions on budget requests | ⬜ |
| 3 | Fittings and appointments; measurement profile with privacy controls | ⬜ |
| 3 | Scope-change versions on orders (mutual approval) | ⬜ |
| 3 | Admin command centre: verification queue, moderation, disputes with recorded reasons, audit log | ⬜ |
| 4 | Payments: provider adapter (e.g. Paystack), server-side verification, idempotent signed webhooks, ledger, refunds, payouts. Do not call it escrow without a legal/provider basis. | ⬜ |
| 4 | Fabric checkout on platform (currently discovery only, clearly labelled) | ⬜ |
| 4 | Notifications (email/SMS/push) via queued jobs | ⬜ |
| 4 | Reviews tied to completed orders only | ⬜ |
| 5 | AI service layer: provider interface, structured-output validation, timeouts, quotas, cost logging, prompt versions, graceful "unavailable" state | ⬜ |
| 5 | AI stylist, image-to-brief (customer edits before posting), smart budget planner, quote explanation, tailor assistant | ⬜ |
| 6 | Mobile: React Native/Expo app on the existing API; push tokens; deep links; App Store privacy disclosures | ⬜ |
| 7 | Search ranking beyond LIKE (only if measurably better); query monitoring | ⬜ |
| — | NDPA 2023 review, consumer-protection and payments obligations — needs qualified professionals | ⬜ |

## Known risks

- Laravel code is unexecuted. Expect a handful of fixes on first boot.
- Fee levels, offer limits and expiry windows are placeholders in `config/marketplace.php` and need business sign-off.
- `TailorProfile::trackRecord()` runs per tailor; precompute or cache before tailor counts reach the hundreds.
- No payment exists, so orders show "unpaid" and customers arrange payment with the tailor. This is stated in the UI.
- Matching uses no real history yet; every seeded tailor is honestly "new".
