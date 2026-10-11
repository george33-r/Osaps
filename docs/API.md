# OSAPS API v1

One versioned API serves the web app and future iOS/Android apps. Base path: `/api/v1`.
Breaking changes go to `/api/v2`; v1 stays stable for shipped mobile builds.

## Conventions

- **Money** is always an object in integer minor units (kobo):
  `{ "amount_minor": 3000000, "currency": "NGN", "formatted": "₦30,000" }`. Clients send `*_minor` integers.
- **Errors** always have the same shape:
  `{ "message": "Human readable", "code": "machine_code", "errors": { "field": ["..."] } }`

| Status | `code` | Meaning |
|---|---|---|
| 401 | `unauthenticated` | Sign in required |
| 403 | `forbidden` | Signed in but not allowed (ownership/role) |
| 404 | `not_found` | Missing, or a hidden resource |
| 409 | `invalid_transition` | Status change not allowed from the current state for this actor |
| 422 | `validation_failed` | Field errors in `errors` |
| 422 | business rule, e.g. `explanation_required`, `counter_limit`, `duplicate_offer`, `offer_expired`, `request_closed`, `reason_required` | A marketplace rule was broken |
| 429 | `rate_limited` | Slow down; respect `Retry-After` |

- **Pagination**: list endpoints return Laravel's `{ data, links, meta }`; use `meta.current_page` / `meta.last_page`.
- **Public IDs**: job requests use ULIDs (`id`), orders use references (`OSP-XXXXXXXX`). Sequential IDs are not exposed for these.
- **`actions`** on offers and orders list what the *current viewer* may do next. They drive UI only; the server re-checks every transition.

## Authentication

| Client | How |
|---|---|
| Web SPA (same origin) | `GET /sanctum/csrf-cookie`, then `POST /auth/login` **without** `device_name`. Cookie session. Send `X-XSRF-TOKEN` on writes. |
| Mobile | `POST /auth/login` **with** `device_name`. Response includes `token` once. Send `Authorization: Bearer <token>`. Store it in iOS Keychain / Android Keystore (e.g. `expo-secure-store`), never AsyncStorage. `POST /auth/logout` revokes that device's token. |

Auth endpoints are throttled (10/min per IP, 5/min per email+IP). Marketplace writes: 20/min per user. Everything else: 120/min.

## Endpoints

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/meta` | anyone | Categories, colours, fee and offer rules, feature flags. Cacheable 5 min. |
| POST | `/auth/register` | anyone | `role`: `customer` \| `tailor` \| `fabric_vendor` (admin is never self-assigned) |
| POST | `/auth/login` | anyone | |
| POST | `/auth/logout` | signed in | |
| GET | `/me` | signed in | |
| PUT | `/me/tailor-profile` | tailor | Create/update own profile (onboarding) |
| GET | `/fabrics` | anyone | Filters: `q`, `category[]`, `color[]`, `min_price`, `max_price` (minor), `in_stock`, `city`, `sort` (`newest`/`price_asc`/`price_desc`), `per_page` ≤ 48 |
| GET | `/fabrics/{slug}` | anyone | |
| GET | `/tailors` | anyone | Filters: `city`, `specialty`, `q`. Verified first, then alphabetical. Never by price or payment. |
| GET | `/tailors/{slug}` | anyone | `track_record` counts completed platform orders only |
| GET | `/job-requests` | customer | Own requests |
| POST | `/job-requests` | customer | See body below |
| GET | `/job-requests/{id}` | owner, admin, eligible tailor | Owner gets every offer with `cost_breakdown` and rule-based `comparison`. Tailor gets the brief, `my_offer` and `match`. |
| POST | `/job-requests/{id}/cancel` | owner, admin | Closes live offers |
| GET | `/job-requests/{id}/matches` | owner, admin | Ranked tailors with plain-language `reasons` |
| GET | `/tailor/inbox` | tailor | Open requests, best match first |
| GET | `/tailor/offers` | tailor | Own offers |
| POST | `/job-requests/{id}/offers` | tailor | Opens a negotiation thread (one per tailor per request) |
| POST | `/offers/{id}/counter` | request owner | `{ amount_minor, note? }` |
| POST | `/offers/{id}/revise` | offer's tailor | `{ amount_minor, delivery_days?, note? }`. Same amount as customer's counter = agree (no round used). |
| POST | `/offers/{id}/withdraw` | offer's tailor | |
| POST | `/offers/{id}/decline` | request owner | |
| POST | `/offers/{id}/accept` | request owner | **Idempotent.** Creates the order (201); a retry returns the same order (200). |
| GET | `/orders` | signed in | Own orders (as customer or tailor) |
| GET | `/orders/{reference}` | parties, admin | |
| POST | `/orders/{reference}/transitions` | parties, admin | `{ status, note? }`. `cancelled` and `disputed` need a note ≥ 10 chars. `disputed` returns 422 `disputes_unavailable` unless `DISPUTES_ENABLED=true`. |
| GET | `/offers/{id}/messages` | the request owner and the offer's tailor | Questions before (and after) accepting. Each item has `mine`. |
| POST | `/offers/{id}/messages` | same | `{ body }` 2–1000 chars. Closed offers return 422 `offer_closed`. |
| GET | `/fabrics/{slug}/contact` | signed in | Vendor phone/WhatsApp/note. `protected_by_osaps: false` — buying is directly with the vendor. |

### AI endpoints (`/ai/…`, throttled; every response says whether AI or the rule-based fallback answered)

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/ai/status` | anyone | `{ available }` |
| GET | `/ai/fabric-search?q=` | anyone | `q` 2–200 chars → `source` (`ai`/`keywords`), `understood`, `criteria`, `results` (real catalogue items only), `relaxed`, `notice` |
| POST | `/ai/stylist` | anyone | `{ known, message?, slot? }` → `next_question` or `result` (concept, catalogue `fabrics`, matched `tailors`, `budget` with each line's basis, `request_draft`) |
| GET | `/ai/fabrics/{slug}/ideas` | anyone | Outfit ideas for one fabric (AI, or the style guide) |
| POST | `/ai/inspiration` | signed in | multipart `image` (jpeg/png/webp ≤ 5 MB). Editable draft brief + caveat. Image is not stored. 503 `ai_unavailable` without a key. |

AI never sets prices, IDs, availability or permissions: the server validates output against a schema, keeps only catalogue IDs it offered, removes price claims and guarantees, and logs token counts (not prompts) in `ai_usages`.

## State machines (server-enforced)

**Offer**: `awaiting_customer` → `accepted` | `declined` | `awaiting_tailor` (customer) ; → `withdrawn` (tailor) ; `awaiting_tailor` → `awaiting_customer` | `withdrawn` (tailor). Either live state → `expired` / `not_selected` (system). Live offers past `expires_at` read as `expired` immediately; the scheduler persists it.

**Order**: `confirmed` → `in_progress` (tailor) → `fitting` ⇄ `in_progress` → `ready` → `delivered` (tailor) → `completed` (customer). Customer may move to `disputed` from `in_progress` onward. Only an admin resolves disputes. After work starts, only an admin can cancel.

## Example: mobile posts a request and accepts an offer

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "email": "ada@example.com", "password": "secret123", "device_name": "Pixel 7" }
```
```json
{ "data": { "id": 7, "name": "Ada Obi", "role": "customer", ... }, "token": "12|Jq9..." }
```

```http
POST /api/v1/job-requests
Authorization: Bearer 12|Jq9...

{
  "title": "Navy senator for a wedding",
  "garment_category": "senator",
  "description": "Classic senator with subtle gold embroidery on the placket and cuffs.",
  "style_tags": ["embroidered"],
  "mode": "budget",
  "budget_max_minor": 3000000,
  "fabric_status": "own_fabric",
  "city": "Abuja",
  "needed_by": "2026-11-04",
  "fitting_required": true
}
```
→ `201 { "data": { "id": "01JC...", "status": "open", "offers_close_at": "...", ... } }`

```http
POST /api/v1/offers/42/accept
Authorization: Bearer 12|Jq9...
```
→ `201 { "data": { "reference": "OSP-7KQ2M9XD", "status": "confirmed", "costs": { "total": { "amount_minor": 3075000, ... } }, "payment_status": "unpaid", "actions": [ ... ] } }`

A network retry of the same accept returns `200` with the same `reference`, so mobile clients can safely retry on timeout.

## Not in v1 yet

Payments, portfolio uploads, push notifications, reviews, dispute resolution console, admin console. See `CHECKLIST.md`.
