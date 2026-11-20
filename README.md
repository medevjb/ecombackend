# E-commerce backend

Laravel 13, PHP 8.4, MySQL 8, Sanctum and Tyro. The backend contract and delivery order are defined in the [PRD](docs/backend_prd.md).

## Architecture

The [Next.js frontend](https://github.com/medevjb/ecommerce) serves the storefront, customer account, and staff console. It calls this application's versioned JSON API under `/api/v1`.

- `routes/api/v1` separates public storefront, customer, staff, authentication, and webhook routes.
- `app/Http` validates requests and serializes responses. `app/Services` owns catalog discovery, pricing, inventory reservations, checkout, order lifecycle, and staff permissions. Wishlist data is scoped to the customer. Internal order notes are available only to staff with both `orders.view` and `orders.note`.
- MySQL stores the catalog, carts, inventory, orders, customer records, and audit history. Uploaded media uses the configured filesystem disk.
- Customer and staff tokens use separate Sanctum guards. Laravel gates enforce staff permissions from `config/staff-access.php` and assigned roles. Customer endpoints also validate record ownership.
- Queue workers deliver mail and process asynchronous work. The scheduler runs reservation expiry and maintenance jobs.

## Local development with MySQL

For a fresh checkout, install dependencies with `composer install` and copy `.env.example` to `.env`. Create an empty MySQL database, configure its credentials in `.env`, and set `APP_URL=http://localhost:8000`. When using one Next.js instance on port 3000, set both `STOREFRONT_ORIGIN` and `ADMIN_ORIGIN` to `http://localhost:3000`. Run the following commands.

```sh
php artisan key:generate --no-interaction
php artisan migrate --seed --no-interaction
php artisan storage:link --no-interaction
php artisan serve --no-interaction
```

For a fresh checkout with a prepared database and local `.env`, `composer setup` installs dependencies, generates the application key, migrates, and builds the assets. Run `php artisan db:seed --no-interaction` for local demo data.

Local seeding adds catalog data and demo users, including `customer@demo.test` and `admin@admin.com`. Both seeded accounts use `password` in local development only.

Run `php artisan queue:work --no-interaction` and `php artisan schedule:work --no-interaction` in separate terminals. The default mail transport logs messages. To use a local Mailpit instance, set `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, and `MAIL_PORT=1025`.

## Local development with Docker Compose

Install Composer dependencies and prepare `.env` as above. Use a non-root `DB_USERNAME` and set a local `DB_PASSWORD` before starting Compose. The MySQL volume is separate from a host MySQL database.

```sh
docker compose up -d --build app mysql redis mailpit
docker compose exec app php artisan key:generate --no-interaction
docker compose exec app php artisan migrate --seed --no-interaction
docker compose exec app php artisan storage:link --no-interaction
docker compose up -d worker scheduler
```

The API defaults to port 8000 and the Mailpit web UI defaults to port 8025. The worker uses the Redis queue. Leave Stripe secrets blank until configuring Stripe Test Mode.

## API and local checks

- `GET /` displays service health and authentication endpoint examples.
- `GET /api/v1/status` reports database connectivity and returns 503 when the connection is unavailable.
- `GET /up` reports Laravel liveness.
- `POST /api/v1/auth/login` accepts credentials for all roles and returns a bearer token and role slugs. Use the returned `data.dashboard` and `data.user.roles` to route authenticated users. An unverified customer receives a verification challenge. Complete it through `POST /api/v1/auth/email/verify-code` before signing in.
- Set `STOREFRONT_ORIGIN` and `ADMIN_ORIGIN` to the origins of your frontend applications. Include the scheme and port when needed, with no trailing slash.

Run `vendor/bin/pest` for the automated tests.

API JSON responses include `success`, `message`, `data`, `meta`, `errors`, and `request_id`. Read the login token from `data.access_token` and role slugs from `data.user.roles`. For failures, use `errors.code` and optional `errors.fields`. Responses with HTTP 204 have an empty body. The `X-Request-Id` header correlates HTTP responses with request logs. See the [OpenAPI contract](docs/api/openapi.yaml) for the status and authentication schemas.

Set `LOG_CHANNEL=stderr` to emit JSON logs in a container. Unexpected API errors log the request ID, exception type, and code location. API error responses expose a stable code and a safe message.

The CI workflow verifies migrations and rollback on disposable MySQL 8.4, then runs PHP formatting, static analysis, backend tests, and OpenAPI validation. Keep deterministic reference data in seeders and create scenario-specific records in tests. Demo credentials belong in local provisioning only.

See [Phase 1 identity and two-factor notes](docs/decisions/0002-identity-and-two-factor.md) for staff and customer guard behavior, the login challenge flow, and email reset setup.

## Interactive API reference

Open `/docs` on the Laravel server to search and expand endpoints by path or HTTP method. Download the same OpenAPI document at `/docs/openapi.yaml`. Use the Postman collection below to send authenticated requests.

Local seeding includes a delivered order and a cancelled order for `customer@demo.test`. These are demo records created without a Stripe transaction.

## Postman

Import the [API collection](docs/api/ecommerce.postman_collection.json) and [local environment](docs/api/local.postman_environment.json), select the environment, and fill in `account_email` and `password` locally. Run **Shared login** first. It stores the customer or staff token for the matching request folders. If login returns HTTP 202, supply `totp_code` and send the staff challenge request.

For a new database, run `php artisan db:seed --class=StaffAccessSeeder --no-interaction`, then run `php artisan staff:bootstrap-super-admin owner@example.test` and enter a password at the hidden prompt. The initial Super Admin can provision other staff accounts from Postman.

See the [demo walkthrough](docs/api/demo.md) for checkout and payment testing, and [operations](docs/operations.md) for deployment, security, and the order state diagram.
