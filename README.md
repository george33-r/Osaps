# Osapa

From fabric to finished outfit — your style, your budget, your choice.

A marketplace where customers post an outfit request (naming their own price or asking for open offers), tailors respond and negotiate, and an accepted offer becomes an order with locked terms and a tracked timeline. Fabric discovery is built in. "Osapa" is a working brand name, configurable via `BRAND_NAME`.

Progress and what is verified: **[CHECKLIST.md](CHECKLIST.md)**. API contract for web and mobile: **[docs/API.md](docs/API.md)**.

## Stack

- **Backend:** Laravel 12 (modular monolith), Sanctum, PHP 8.2+. Business rules live in `app/Domain` with no framework dependencies; `app/Services` applies them in database transactions.
- **Frontend:** React 19 + TypeScript + Vite 8 in `web/`, built into `public/spa` and served by Laravel at every non-API path.
- **Database:** SQLite for development; PostgreSQL recommended for production.

## First run on your machine

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # demo data only runs in local/testing

php tests/bin/run-domain-tests.php  # business rules (needs no Composer)
php artisan test                    # full Laravel suite

npm --prefix web install
npm --prefix web run build          # outputs public/spa
php artisan serve                   # http://localhost:8000
```

Demo accounts (local only, password `demo-pass-123`): `customer@demo.osapa.test`, `tailor1@demo.osapa.test` … `tailor6@`, `admin@demo.osapa.test`.

### Developing the frontend

```bash
php artisan serve                   # terminal 1, port 8000
npm --prefix web run dev            # terminal 2, http://localhost:5173 (proxies /api and /sanctum)
```

`npm --prefix web run preview:fixtures` runs the UI against an in-memory design-review adapter with demonstration data and a visible banner. It never ships in production builds.

## Running in production

- Scheduler (expires stale offers and requests): `* * * * * php artisan schedule:run`
- Queue worker (for notifications/AI once added): `php artisan queue:work`
- Set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and `SANCTUM_STATEFUL_DOMAINS` to your domain.
- Never run `DemoSeeder` in production; it refuses to.
- Back up the database daily and test restores; orders, offer revisions and order events are the audit trail.

## Layout

```
app/Domain/        Framework-free rules: Money, FeeSchedule, OfferRules, state machines, TailorScorer, QuoteComparison
app/Services/      NegotiationService, OrderService, MatchingService — all state changes happen here
app/Http/          Controllers (Api/V1), Form Requests, Resources, Policies
config/marketplace.php   Fees, limits, expiry, matching weights, categories
web/src/           React app: api/ (typed client + preview adapter), components/, pages/, styles/
tests/Unit/Domain  Runs with or without PHPUnit
tests/Feature/Api  Laravel HTTP tests
```
