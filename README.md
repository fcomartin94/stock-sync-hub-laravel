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
- Receives WooCommerce webhooks for near-real-time updates, as an alternative
  to waiting for the next hourly sync.

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

## Data model

`products` and `orders` each have a unique composite key on
`(store_id, external_id)` — the platform's own ID plus which store it came
from — so the same product/order can be safely re-synced without duplicating
rows. `stock_snapshots` is intentionally append-only: each sync run adds a new
row rather than overwriting the last one, which is what makes a stock history
over time possible.

## Connectors

`StoreConnectorInterface` defines `getProducts()`, `getStock()`, `getOrders()`,
all returning a normalized array shape independent of the source platform.

- `PrestaShopConnector` — Basic Auth with the Webservice API key as username,
  `output_format=JSON`, handling PrestaShop's multi-language `name` field
  (an array keyed by language ID) and its separate `stock_availables`
  resource for quantities.
- `WooCommerceConnector` — Basic Auth with consumer key/secret, hitting the
  WooCommerce REST API v3 (`/wp-json/wc/v3/...`), where stock is a flat field
  on the product resource itself.

Both are covered by unit tests using `Http::fake()` to simulate each
platform's real response shape without needing a live store.

## Sync job

`SyncStoreJob` (queued, `ShouldQueue`) resolves the correct connector for a
`Store` based on its `platform` column, fetches products and orders, and
upserts them keyed on `(store_id, external_id)`, plus a new `StockSnapshot`
row on every run. Registered via the Laravel scheduler, dispatching one job
per store hourly.

## WooCommerce webhook receiver

`POST /api/webhooks/woocommerce` sits outside the `auth:sanctum` group,
since Sanctum tokens make no sense for a third-party platform pushing events
to this app. Instead, the endpoint authenticates the request itself by
verifying the `X-WC-Webhook-Signature` header: WooCommerce signs every webhook
body with HMAC-SHA256 using a shared secret, base64-encoded. The controller
recomputes the same HMAC over the raw request body and compares it to the
header with `hash_equals()` (constant-time, to avoid timing attacks),
rejecting the request with `401` on any mismatch.

Handles `order.created`/`order.updated` and `product.created`/
`product.updated` topics, resolving which `Store` the event belongs to via the
`X-WC-Webhook-Source` header matched against `stores.base_url`, then upserting
the same way `SyncStoreJob` does. This means a store can be kept up to date
either by the hourly scheduled pull or by near-real-time pushes from
WooCommerce, without divergent logic between the two paths.

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
separate services, matching the containerized approach used elsewhere in this
portfolio. A custom `docker-compose.yml` and `docker/php/Dockerfile` are used
instead of Laravel Sail, to keep full control over service definitions and
stay consistent with the rest of the portfolio.

### Requirements

- Docker Desktop
- Composer (for the initial `laravel/laravel` scaffolding step only —
  everything after that runs inside the container)

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
  local MySQL instances
- `redis` — queue backend, exposed on host port `6380`

```bash
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

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

## Security notes

- Store credentials (`api_key`, `api_secret`) are hidden from every API response and
  stored encrypted at rest (Laravel's `encrypted` cast, keyed by `APP_KEY`). A migration
  encrypts any plaintext credentials already in the database.
- The WooCommerce webhook is authenticated with an HMAC-SHA256 signature checked with
  `hash_equals`. It uses a single shared secret (`WOOCOMMERCE_WEBHOOK_SECRET`); a
  per-store secret is a natural next step if several WooCommerce stores post to the same
  instance.
- The sync job retries up to 3 times with backoff before it is marked as failed.

## Roadmap

- [x] Migrations: `stores`, `products`, `stock_snapshots`, `orders`
- [x] `StoreConnectorInterface` + `PrestaShopConnector` + `WooCommerceConnector`
- [x] Queued sync job + Laravel scheduler entry
- [x] Sanctum-authenticated API endpoints (products, stock alerts, orders, manual sync)
- [x] Test suite (PHPUnit) mocking external APIs with `Http::fake()`
- [x] WooCommerce webhook receiver with HMAC signature verification

## License

Released under the [MIT License](LICENSE).
