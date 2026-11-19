# Inventory and reservation guarantees

The inventory row is the stock source of truth for one sellable variant at one logical location. Available stock is on-hand minus reserved. MySQL enforces that reserved stock cannot exceed on-hand, and every write also checks the invariant in the service.

Admin adjustments require a staff permission, a reason, an integer delta, and an `Idempotency-Key` header. Identical requests return the existing movement. Reusing a key for another payload returns `IDEMPOTENCY_CONFLICT`. Review the per-variant ledger at `GET /api/v1/admin/inventory/movements`. Set `INVENTORY_LOW_STOCK_THRESHOLD` to configure the low-stock counts and filters.

Reservations hold available stock without changing on-hand. The service locks inventory rows in ascending variant ID. Only the verified payment workflow will call commit; it deducts and records stock once. Expiration and release return the hold. A late payment after expiry rechecks available stock under lock and fails with `INSUFFICIENT_STOCK` if it cannot be fulfilled. Phase 8 will connect that result to payment reconciliation. A verified full cancellation can restore committed stock once.

The Laravel scheduler runs `inventory:expire-reservations` every minute. Run `php artisan schedule:work --no-interaction` locally when testing pending reservations. The concurrency integration test requires a disposable MySQL database. It runs two separate processes through a common barrier and verifies that only one can reserve the final unit and that both concurrent adjustments are retained.

The [Postman collection](../api/ecommerce.postman_collection.json) includes all three inventory endpoints. Set `variant_id` and choose a new `idempotency_key` for each new adjustment.
