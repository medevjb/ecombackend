# Postman demo

Import the [collection](ecommerce.postman_collection.json) and [local environment](local.postman_environment.json). Set `base_url` to your backend origin. Use Postman local values for passwords, bearer tokens, and other secrets.

## Five-minute walkthrough

1. Run **Register** with a new customer email. Read the six-digit code from Mailpit or your local mail log. Run **Verify a customer email with a code** with that email and code, then run **Shared login**. Confirm that `data.dashboard` is `customer` and Postman stored the token. Open **New Arrivals**, select an in-stock variant, and fill the collection's product and variant variables.
2. Run **Add cart item**, apply the seeded `DEMO10` coupon, and create a shipping address. Set `address_id` from its response. Run **Checkout preview** and review the server-calculated discount, shipping, and total.
3. Run **Create or resume a pending Test Mode checkout** with a fresh `checkout_key`. Confirm the returned PaymentIntent client secret with Stripe.js in the storefront using a Test Mode payment method, or use the Stripe dashboard or CLI to confirm the corresponding Test Mode PaymentIntent. Reusing the checkout key returns the same attempt.
4. After Stripe forwards the signed success event and the queue processes it, read the customer order. Sign in via the same **Shared login** request as Super Admin, Admin, or Order Manager. Use the staff order transitions to move the order to processing, shipped with carrier and tracking, then delivered.
5. Repeat with a fresh cart and the Stripe Test Mode decline scenario. Check that stock is released and the order shows the resulting payment state. Sign in as Support and try a staff status transition. The API should return HTTP 403. Run the MySQL integration suite to check concurrent attempts against limited stock.

Stripe.js and the Stripe CLI need local Stripe Test Mode configuration. Test cards and the CLI setup are documented by Stripe. [Stripe test cards](https://docs.stripe.com/testing) · [Stripe CLI forwarding](https://docs.stripe.com/stripe-cli/use-cli)

## Stripe and mail setup

Place a Stripe Test Mode secret in the local `.env`. Run the Stripe CLI listener with `stripe listen --forward-to "$API_BASE/api/v1/webhooks/stripe"` and save its signing secret as `STRIPE_WEBHOOK_SECRET`. Keep the queue worker and scheduler running. Confirm the same PaymentIntent returned by checkout so the event matches the order. The Postman webhook request illustrates the contract and requires a valid Stripe signature for its exact body.

Set up SMTP to Mailpit to inspect order status and newsletter notices. The newsletter confirmation supplies the token accepted by the **Suppress a subscriber using the mailed code** request.
