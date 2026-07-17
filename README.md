# Stock Sync Hub — Laravel API

A Laravel REST API that acts as a synchronization layer between independent
e-commerce platforms — PrestaShop and WooCommerce — normalizing their products,
stock, and orders into a single data model and exposing them through one
authenticated API.

## Why this project

The rest of this portfolio (`brew-co-prestashop`, `mythic-books-woocommerce`,
`volt-circuit-prestashop-seo`, `feedgoogleshopping-*`) is built around configuring,
auditing, and extending existing e-commerce platforms. This project is
deliberately different: it's a standalone backend service with no CMS
underneath it, built to demonstrate framework-level backend engineering — API
design, queued jobs, authentication, and testing — using Laravel as the "glue"
between two systems that don't natively talk to each other.

It was originally designed against two real stores from this portfolio
(Brew & Co. on PrestaShop, Mythic Books on WooCommerce), but both were
short-lived local demo environments that are no longer running. Rather than
rebuilding either store just to keep a live integration, both connectors are
validated with `Http::fake()` tests that reproduce the exact response shapes
documented by each platform's real API (PrestaShop Webservice API, WooCommerce
REST API v3). This is a deliberate, standard testing practice for integrations
against third-party systems you don't control — not a workaround for missing
functionality. The connector code itself makes no assumption about mocking; it
would work unchanged against a live store with valid credentials.

## What it does

- Connects to a PrestaShop store and a WooCommerce store through a shared
  `StoreConnectorInterface`, so both platforms are queried through the same
  contract despite having unrelated APIs and authentication schemes.
- Normalizes products, stock levels, and orders from both platforms into a
  single Laravel data model (`stores`, `products`, `stock_snapshots`, `orders`).
- Runs a queued sync job (`SyncStoreJob`) per store, dispatched hourly by the
  Laravel scheduler, that fetches products/orders through the connector,
  upserts them, and records a stock snapshot on every run — building a stock
  history over time.
- Exposes a token-authenticated (Laravel Sanctum) REST API: store list, manual
  per-store sync trigger, aggregated products, low-stock alerts, per-product
  stock history, and recent orders.

## Stack

- Laravel 13 (PHP 8.4)
- MySQL 8.0
- Redis (queue backend)
- Laravel Sanctum (API token authentication)
- Docker Compose (nginx + php-fpm + mysql + redis)
- PHPUnit (this project's Laravel 13 install ships without Pest; tests use
  `Tests\TestCase` + `Illuminate\Foundation\Testing\RefreshDatabase`)

## Architecture

```
┌──────────────────┐        ┌──────────────────┐
│  PrestaShop store │        │  WooCommerce store│
└────────┬─────────┘        └────────┬─────────┘
         │ Webservice API             │ REST API v3
         ▼                            ▼
   PrestaShopConnector          WooCommerceConnector
         │                            │
         └──────────┬─────────────────┘
                     ▼
          StoreConnectorInterface
                     │
                     ▼
       SyncStoreJob (queued, hourly via
       Laravel scheduler) → normalized DB
       (stores, products, stock_snapshots,
       orders)
                     │
                     ▼
          REST API (Sanctum-authenticated)
```

## API Endpoints

All routes require a Sanctum bearer token (`Authorization: Bearer <token>`).

| Method | Endpoint                          | Description                                  |
|--------|------------------------------------|-----------------------------------------------|
| GET    | `/api/stores`                     | List all connected stores                    |
| POST   | `/api/stores/{store}/sync`        | Dispatch a manual sync job for one store     |
| GET    | `/api/products`                   | Paginated products, optional `?store_id=`    |
| GET    | `/api/products/low-stock`         | Products at or below `?threshold=` (default 5) |
| GET    | `/api/products/{product}/stock-history` | Stock snapshot history for one product |
| GET    | `/api/orders/recent`              | Most recent 50 orders, optional `?store_id=` |

The webhook endpoint below is public (no Sanctum token) and authenticates
itself via HMAC signature verification instead:

| Method | Endpoint                          | Description                                  |
|--------|------------------------------------|-----------------------------------------------|
| POST   | `/api/webhooks/woocommerce`       | Receives WooCommerce `order.*`/`product.*` events |

## Local Environment Setup

The project runs entirely in Docker: nginx, PHP-FPM, MySQL, and Redis as four
separate services, matching the containerized approach already used in
`brew-co-prestashop` and `feedgoogleshopping-woocommerce`.

### Requirements

- Docker Desktop
- Composer (for the initial `laravel/laravel` scaffolding step only — everything
  after that runs inside the container)

### Setup

```bash
composer create-project laravel/laravel stock-sync-hub-laravel
cd stock-sync-hub-laravel
composer require laravel/sanctum
php artisan install:api
```

Docker Compose brings up four services:

- `app` — PHP-FPM running the Laravel codebase
- `nginx` — web server, exposed on `localhost:8080`
- `db` — MySQL 8.0, exposed on host port `3307` to avoid clashing with other
  local MySQL instances (as previously happened with XAMPP in `brew-co-prestashop`)
- `redis` — queue backend, exposed on host port `6380`

```bash
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

App available at `http://localhost:8080`.

### Generating an API token for manual testing

```bash
docker compose exec app php artisan tinker
```

```php
$user = App\Models\User::factory()->create();
$token = $user->createToken('test-token')->plainTextToken;
echo $token;
```

```bash
curl -H "Authorization: Bearer <token>" -H "Accept: application/json" http://localhost:8080/api/stores
```

### Running the test suite

```bash
docker compose exec app php artisan test
```

## Progress Log

### Step 1 — Project scaffolding

Created via `composer create-project laravel/laravel`, then added Sanctum
(`laravel/sanctum`) via `php artisan install:api` for token-based API
authentication. Declined running the published Sanctum migration immediately,
since no database was available yet at that point — the container stack
(Step 2) had to exist first.

### Step 2 — Docker Compose stack

Wrote a custom `docker-compose.yml` and `docker/php/Dockerfile` rather than
using Laravel Sail, to keep full control over the service definitions and stay
consistent with the Docker setup already used elsewhere in this portfolio.
Four services: `app` (PHP-FPM), `nginx`, `db` (MySQL 8.0), `redis`. Host ports
for `db` (`3307`) and `redis` (`6380`) were deliberately offset from the
defaults (`3306`/`6379`) to avoid collisions with other local services.

### Step 3 — PHP version mismatch between host and container

**Problem:** `docker compose up -d --build` failed at the `composer install`
step inside the image with:

```
symfony/http-foundation v8.1.1 requires php >=8.4.1 -> your php version (8.3.32) does not satisfy that requirement.
```

**Root cause:** `composer create-project` had been run locally against the
machine's installed PHP version, which was 8.4+. Composer resolved and locked
dependency versions (Laravel 13 pulls in Symfony 8.x components) against that
PHP version in `composer.lock`. The Docker image, however, was originally
built from `php:8.3-fpm`. Since `composer.lock` pins exact resolved versions,
`composer install` inside the container re-validates those pinned versions
against the container's PHP version — not the host's — and 8.3 didn't satisfy
the Symfony 8.x minimum of PHP 8.4.1.

**Fix:** changed the base image in `docker/php/Dockerfile` from `php:8.3-fpm`
to `php:8.4-fpm`, aligning the container's PHP version with the one that had
already produced the lock file.

### Step 4 — `.env` / `docker-compose.yml` drift

**Problem:** three inconsistencies were found between `.env` and
`docker-compose.yml` before the stack was ever run against a real database:

1. `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` were
   commented out in `.env`, which would have left Laravel falling back to
   default/empty connection values instead of the `db` service.
2. `DB_DATABASE` in `.env` (`stock_sync_hub_laravel`) didn't match
   `MYSQL_DATABASE` in `docker-compose.yml` (`stock_sync_hub`) — two different
   database names, which would have caused Laravel to connect successfully to
   MySQL but fail to find its target database.
3. `.env` declared `REDIS_PASSWORD=redis`, but the `redis:alpine` service in
   `docker-compose.yml` was never configured with a password — Laravel would
   have attempted to authenticate against a Redis instance that doesn't
   require or expect authentication.

**Fix:** uncommented and filled in the five `DB_*` variables in `.env`,
aligned `MYSQL_DATABASE` in `docker-compose.yml` to `stock_sync_hub_laravel`,
and set `REDIS_PASSWORD=null` to match the unauthenticated Redis container.

### Step 5 — MySQL volume initialized with stale credentials

**Problem:** after fixing Step 4, `php artisan migrate` failed with
`Access denied for user 'laravel'@'%' to database 'stock_sync_hub_laravel'`.

**Root cause:** MySQL only runs its `MYSQL_DATABASE`/`MYSQL_USER`/
`MYSQL_PASSWORD` initialization on a *fresh* volume. The `db_data` volume had
already been created by an earlier `docker compose up` run, using the old
(pre-fix) database name — so the `laravel` user existed, but scoped to a
database that no longer matched what `.env` requested.

**Fix:** `docker compose down -v` to drop the volume, then `docker compose up
-d --build` to let MySQL reinitialize from scratch with the corrected
environment variables. Since no real data existed yet, this had no cost.

### Step 6 — Data model

Created migrations and Eloquent models for `stores`, `products`,
`stock_snapshots`, and `orders`. Key design decisions:

- `products` and `orders` each have a unique composite key on
  `(store_id, external_id)` — the platform's own ID plus which store it came
  from — so the same product/order can be safely re-synced without
  duplicating rows (`updateOrCreate` relies on this).
- `stock_snapshots` is intentionally append-only (always `create`, never
  `updateOrCreate`): each sync run adds a new row rather than overwriting the
  last one, which is what makes a stock history over time possible.

Verified relationships end-to-end via `php artisan tinker`: created a `Store`,
attached a `Product` through the `hasMany` relationship, and confirmed
`$store->products` returned it correctly.

### Step 7 — Brew & Co. / Mythic Books no longer available

**Problem:** the original plan was to point both connectors at the two real
stores already in this portfolio. Neither is currently running — Brew & Co.
was a short-lived local PrestaShop environment that was never meant to be kept
alive long-term, and Mythic Books is in the same situation.

**Decision:** rather than rebuilding either store, both connectors are tested
against `Http::fake()` responses shaped exactly like each platform's
documented real API output (see Step 8). This is called out explicitly here,
and in the "Why this project" section above, instead of leaving it implicit —
the connector implementations are written the same way they would be for a
live store; only the verification method differs.

### Step 8 — `StoreConnectorInterface`, `PrestaShopConnector`, `WooCommerceConnector`

Defined `StoreConnectorInterface` (`getProducts()`, `getStock()`,
`getOrders()`, all returning a normalized array shape independent of the
source platform) and two implementations:

- `PrestaShopConnector` — Basic Auth with the Webservice API key as username,
  `output_format=JSON`, handling PrestaShop's multi-language `name` field
  (an array keyed by language ID) and its separate `stock_availables`
  resource for quantities.
- `WooCommerceConnector` — Basic Auth with consumer key/secret, hitting the
  WooCommerce REST API v3 (`/wp-json/wc/v3/...`), where stock is a flat field
  on the product resource itself.

Both are covered by `tests/Unit/PrestaShopConnectorTest.php` and
`tests/Unit/WooCommerceConnectorTest.php`, using `Http::fake()` to simulate
each platform's real response shape without needing a live store.

**Note on the test setup:** the initial test files were written using Pest
syntax (`it(...)`), which failed with `Call to undefined function it()` —
this Laravel 13 installation ships with PHPUnit only, not Pest. Rewritten as
standard PHPUnit test classes extending `Tests\TestCase` (not bare
`PHPUnit\Framework\TestCase`), since `Http::fake()` needs the Laravel
application container bootstrapped to intercept the `Http` facade.

### Step 9 — `SyncStoreJob` + scheduler

Created a queued job (`ShouldQueue`) that resolves the correct connector for
a `Store` based on its `platform` column, fetches products and orders, and
upserts them via `updateOrCreate` keyed on `(store_id, external_id)`, plus a
new `StockSnapshot` row on every run. Registered in `routes/console.php` via
`Schedule::call(...)->hourly()`, dispatching one `SyncStoreJob` per store.

Verified with `tests/Unit/SyncStoreJobTest.php`, using
`RefreshDatabase` + `Http::fake()` together: fakes a full PrestaShop response
cycle (products, stock, orders), runs the job synchronously, and asserts the
resulting rows in `products`, `orders`, and `stock_snapshots`, plus that
`last_synced_at` gets updated on the store.

### Step 10 — API endpoints

Added `StoreController`, `ProductController`, `OrderController` under
`app/Http/Controllers/Api/`, registered in `routes/api.php` behind
`auth:sanctum` middleware. Two issues surfaced during this step:

1. **Duplicate `use Illuminate\Support\Facades\Route;`** — `routes/api.php`
   already had this import from the Sanctum-generated boilerplate; adding a
   second one caused a fatal "Cannot use ... as Route because the name is
   already in use". Fixed by removing the duplicate.
2. **`User` model missing `HasApiTokens`** — `createToken()` failed with
   `Call to undefined method App\Models\User::createToken()`. This Laravel 13
   scaffold uses PHP attributes (`#[Fillable]`, `#[Hidden]`) instead of the
   classic `$fillable`/`$hidden` properties, but still needed the
   `Laravel\Sanctum\HasApiTokens` trait added manually to the `use` clause —
   `install:api` publishes Sanctum's config and migration but does not modify
   the `User` model itself.

Also worth noting: `/api/products/low-stock` is registered before
`/api/products/{product}/stock-history` in `routes/api.php`. Route order
matters here — if the parameterized route were declared first, Laravel would
try to resolve `low-stock` as a `{product}` route-model-binding value instead
of matching the literal segment.

Verified end-to-end with `curl` against the running stack (real HTTP request,
real Sanctum token, real database row) — `/api/stores` and `/api/products`
both returned the expected JSON, including the `Store`/`Product` created in
Step 6.

### Step 11 — WooCommerce webhook receiver

Added `WooCommerceWebhookController@handle`, registered at
`POST /api/webhooks/woocommerce` — deliberately outside the `auth:sanctum`
group, since Sanctum tokens make no sense for a third-party platform pushing
events to this app. Instead, the endpoint authenticates the request itself by
verifying the `X-WC-Webhook-Signature` header: WooCommerce signs every webhook
body with HMAC-SHA256 using a shared secret, base64-encoded. The controller
recomputes `base64_encode(hash_hmac('sha256', $rawBody, $secret, true))` and
compares it to the header with `hash_equals()` (constant-time, to avoid timing
attacks), rejecting the request with `401` on any mismatch.

Handles `order.created`/`order.updated` and `product.created`/
`product.updated` topics (read from `X-WC-Webhook-Topic`), resolving which
`Store` the event belongs to via the `X-WC-Webhook-Source` header matched
against `stores.base_url`, then upserting the same way `SyncStoreJob` does —
keyed on `(store_id, external_id)`, with a new `StockSnapshot` row on product
updates. This means the same store can be kept up to date either by the
hourly scheduled pull (`SyncStoreJob`) or by near-real-time pushes from
WooCommerce, without divergent logic between the two paths.

Covered by `tests/Feature/WooCommerceWebhookControllerTest.php`: one test
confirms a request with an invalid signature is rejected with `401`; the
other computes a real HMAC signature over a JSON payload and confirms the
order gets upserted correctly. That second test intentionally uses
`$this->call(...)` with a manually-serialized JSON string instead of Laravel's
`postJson()` helper — `postJson()` re-serializes the given array internally,
which could produce a byte-for-byte different string than the one the
signature was computed over, since HMAC verification is sensitive to the
exact bytes of the body.

## Roadmap

- [x] Migrations: `stores`, `products`, `stock_snapshots`, `orders`
- [x] `StoreConnectorInterface` + `PrestaShopConnector` + `WooCommerceConnector`
- [x] Queued sync job + Laravel scheduler entry
- [x] Sanctum-authenticated API endpoints (products, stock alerts, orders, manual sync)
- [x] Test suite (PHPUnit) mocking external APIs with `Http::fake()`
- [x] WooCommerce webhook receiver with HMAC signature verification
