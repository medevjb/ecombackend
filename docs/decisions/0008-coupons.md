# Coupons and usage claims

Coupon codes are normalized to uppercase. A percent value has up to two decimals, stored as integer basis points. A fixed value is an integer amount in USD minor units. Discounts round down to the nearest cent and never exceed the eligible item subtotal. Shipping is excluded. Product and category restrictions match either a selected product or one of its directly assigned categories.

Cart application is an estimate. It recomputes from catalog prices and the saved coupon rules on each read. Guests can preview a coupon. Per-customer limits are enforced after identification. The API returns stable codes for expired, exhausted, ineligible and minimum-spend cases.

Checkout will reserve a claim under a per-coupon row lock and link it to the order's public ULID. Repeated requests with the same checkout snapshot return the existing claim. A successful payment consumes it once. Unpaid cancellations can release a hold, and expired holds stop counting toward the limit. Redeemed uses remain recorded.

Use the [Postman collection](../api/ecommerce.postman_collection.json) for administrative and cart coupon endpoints. Data used for discounts always comes from the server.
