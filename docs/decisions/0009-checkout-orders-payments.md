# Checkout, orders, and payments

Checkout uses current server-side prices and writes immutable line, address, shipping, and total snapshots when it reserves stock. Customers send an `Idempotency-Key` when starting checkout. Retrying an existing attempt retains its original totals. Signed Stripe Test Mode webhooks reconcile payments asynchronously. Refunds use provider idempotency keys and only verified cancellation refunds restore stock.

Shipping defaults to $5.00 USD. Staff with `shipping.manage` can read or update the flat fee at `/api/v1/admin/shipping-settings`. Send the amount in cents as `flat_shipping_amount`, with zero for free shipping. Until an admin saves a rate, deployments can set the initial value through `CHECKOUT_FLAT_SHIPPING_AMOUNT`.

For a local Stripe walkthrough, set `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` in the environment, start the queue worker and scheduler, and forward signed Stripe CLI events to `/api/v1/webhooks/stripe`. Configure `CHECKOUT_OPERATIONS_EMAIL` to receive payment exception notices. [Stripe webhook testing](https://docs.stripe.com/webhooks#test-webhook)
