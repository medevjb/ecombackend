# E-commerce backend

Laravel 13, PHP 8.4, MySQL 8, Sanctum and Tyro. The backend contract and delivery order are defined in the [PRD](docs/backend_prd.md).

## Local development with MySQL

For a fresh checkout, install dependencies with `composer install` and copy `.env.example` to `.env`. Set your local database credentials in `.env`, then run:

```sh
php artisan key:generate --no-interaction
php artisan migrate --no-interaction
php artisan serve --no-interaction
```

Use `php artisan queue:work --no-interaction` in a separate terminal for the local database queue. The default mail transport logs messages. To use a local Mailpit instance, set `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, and `MAIL_PORT=1025`.

## Local development with Docker Compose

Install Composer dependencies and prepare `.env` as above. Use a non-root `DB_USERNAME` and set a local `DB_PASSWORD` before starting Compose. The MySQL volume is separate from a host MySQL database.

```sh
docker compose up -d --build app mysql redis mailpit
docker compose exec app php artisan key:generate --no-interaction
docker compose exec app php artisan migrate --no-interaction
docker compose up -d worker
```

The API defaults to port 8000 and the Mailpit web UI defaults to port 8025. The worker uses the Redis queue. Leave Stripe secrets blank until configuring Stripe Test Mode.

## API and local checks

- `GET /` displays service health and authentication endpoint examples.
- `GET /api/v1/status` reports database connectivity and returns 503 when the connection is unavailable.
- `GET /up` reports Laravel liveness.
- `POST /api/v1/auth/login` accepts credentials for all roles and returns a bearer token and role slugs. See the [foundation decisions](docs/decisions/0001-api-foundation.md) for the planned customer and staff identity response.
- Set `STOREFRONT_ORIGIN` and `ADMIN_ORIGIN` to the origins of your frontend applications. Include the scheme and port when needed, with no trailing slash.

Run `vendor/bin/pest` for the automated tests.

API JSON responses include `success`, `message`, `data`, `meta`, `errors`, and `request_id`. Read the login token from `data.access_token` and role slugs from `data.user.roles`. For failures, use `errors.code` and optional `errors.fields`. Responses with HTTP 204 have an empty body. The `X-Request-Id` header correlates HTTP responses with request logs. See the [OpenAPI contract](docs/api/openapi.yaml) for the status and authentication schemas.

Set `LOG_CHANNEL=stderr` to emit JSON logs in a container. Unexpected API errors log the request ID, exception type, and code location. API error responses expose a stable code and a safe message.

The CI workflow verifies migrations and rollback on disposable MySQL 8.4, then runs PHP formatting, static analysis, backend tests, and OpenAPI validation. Keep deterministic reference data in seeders and create scenario-specific records in tests. Demo credentials belong in local provisioning only.

See [Phase 1 identity and two-factor notes](docs/decisions/0002-identity-and-two-factor.md) for staff and customer guard behavior, the login challenge flow, and email reset setup.

## Postman

Import the [API collection](docs/api/ecommerce.postman_collection.json) and [local environment](docs/api/local.postman_environment.json), select the environment, and fill in `account_email` and `password` locally. Run **Shared login** first. It stores the customer or staff token for the matching request folders. If login returns HTTP 202, supply `totp_code` and send the staff challenge request.

For a new database, run `php artisan db:seed --class=StaffAccessSeeder --no-interaction` to install Phase 2 role and privilege defaults. The seeder creates roles and permissions. Staff identities and credentials are provisioned separately.
