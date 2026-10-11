# Implementation checklist

Status as of 11 Oct 2026. "Verified" means it was executed in this environment, not just written.

**Environment limit:** Packagist is blocked in the build environment, so `composer install` cannot run here.
The Laravel layer has **never been booted in this environment**. The live Hostinger site serves the earlier version of it
(`/api/v1/meta`, `/fabrics`, `/tailors` return JSON), but the code added in the OSAPS phase is unexecuted until deployed.
The GitHub Actions workflow in `.github/workflows/ci.yml` runs the full suite wherever Composer can reach Packagist.

## Legend

- ✅ **Verified** — executed here and passed
- 🟡 **Written, not run** — code complete; needs `composer install` to execute
- ⬜ **Not started**

## Core marketplace (earlier phases)

| Area | Status | Evidence |
|---|---|---|
| Domain rules: money in kobo, fees, offer rules, state machines, matching, quote comparison | ✅ | `php tests/bin/run-domain-tests.php` |
| Migrations, API v1, policies, idempotent accept, expiry sweep, rate limits | 🟡 | Feature tests in `tests/Feature/Api` |
| Live site on Hostinger (subfolder layout, installer, `.env` and logs unreachable from the web) | ✅ | Fetched from the live domain |

## OSAPS phase

| Area | Status | Evidence |
|---|---|---|
| AI safety rules (schema validator, JSON extraction, criteria sanitising + relaxation, keyword reader, consultation slots, budget estimate, grounding to offered IDs, claim scrubbing) | ✅ | 15 new domain tests; **52 domain tests pass in total**; a deliberately injected Grounding bug was caught |
| AI layer: provider interface, Anthropic provider (server-side key), unavailable/fake providers, gateway (timeouts, per-minute + daily limits, usage logging without prompt text), rule-based fallbacks | 🟡 | `tests/Feature/Api/AiFeaturesTest.php` (11 tests, FakeProvider — no network) |
| Natural-language fabric search (real catalogue items only, relaxes and says so) | 🟡 backend · ✅ UI in preview | Flow + screenshots |
| AI stylist: one question at a time, concept, catalogue fabrics, matched makers, budget with each line's basis, "Turn this into a request" | 🟡 backend · ✅ UI in preview (guided mode) | Flow steps 18–19 |
| Image-to-brief (editable, caveat, image not stored; off without a key) | 🟡 | Feature test; UI shows "switches on when AI is enabled" |
| Fabric outfit ideas (AI, or the style guide) | 🟡 backend · ✅ UI in preview | Screenshot |
| Ask questions before accepting (offer messages, parties only, closed offers read-only) | 🟡 backend · ✅ UI in preview | Flow step 8; `MessagingAndContactTest` (6 tests) |
| Minimum-only budget accepted | 🟡 | Validation rule + feature test |
| Disputes off until someone can resolve them (`DISPUTES_ENABLED`), help route shown instead | 🟡 backend · ✅ UI in preview | Flow step 16 |
| Vendor contact for signed-in users, labelled "not protected by OSAPS" | 🟡 backend · ✅ UI in preview | Screenshot |
| OSAPS identity: logo, ivory/espresso/oxblood/gold/emerald/indigo palette, Bodoni Moda + Hanken Grotesk, editorial layouts | ✅ | Screenshots at 390 px and 1440 px of 11 screens; no console errors; no horizontal overflow |
| Homepage: cinematic hero, three edits, stylist invitation, process, makers, editorial footer | ✅ in preview | Screenshots |
| Five-step request consultation with server errors mapped back to the right step | ✅ in preview | Flow steps 3–7 |
| Full journey (19 steps, three roles) | ✅ in preview | Playwright |
| Production bundle excludes the preview adapter | ✅ | Bundle grep |
| Contrast: text colours ≥ 4.5:1 (gold text uses `#7E6230`, 5.2:1); gold `#A98545` (3.1:1) only for rules, dots and the focus ring | ✅ | Calculated |

"Preview" means the frontend running against the in-memory adapter (`npm run preview:fixtures`). It proves the UI, not Laravel.

## Honesty rules in force

- No testimonials, ratings, review counts, "verified" badges or order histories that do not exist. Every seeded tailor reads "New maker on OSAPS".
- Fabric pictures are drawn from each listing's pattern and colours and always captioned as illustrations. The hero only shows a photograph when `BRAND_CAMPAIGN_IMAGE` points to one you have the rights to.
- AI never sets prices, IDs, availability or permissions, and every AI answer says whether AI or the rule-based fallback produced it.

## Remaining work

| Item | Status |
|---|---|
| Deploy, run the installer upgrade, then run the feature suite (CI or a machine with Composer) and fix what it surfaces | ⬜ |
| Licensed campaign and product photography (vendor photo upload) | ⬜ |
| Admin console: verification queue, moderation, dispute resolution, audit log — then turn disputes on | ⬜ |
| Payments (provider adapter, verified webhooks, ledger, refunds, payouts) | ⬜ |
| Notifications (email/WhatsApp/SMS) for new offers and messages | ⬜ |
| Tailor portfolio uploads | ⬜ |
| Tailor assistant (draft an offer from a brief) and support assistant | ⬜ |
| AI quote explanation beyond the rule-based comparison | ⬜ |
| Fittings, measurements, scope-change versions | ⬜ |
| Mobile app on the same API | ⬜ |
| NDPA 2023 and consumer-protection review by qualified professionals | ⬜ |

## Known risks

- New Laravel code is unexecuted; expect small fixes on first boot.
- The logo's varsity/streetwear style differs from the "quiet couture" brief; the layout is designed to hold it, but it is a brand decision.
- Fees, limits and AI quotas in `config/marketplace.php` and `config/ai.php` are placeholders.
- AI cost depends on traffic; daily per-user and per-IP limits cap it, and `ai_usages` records token counts per feature.
