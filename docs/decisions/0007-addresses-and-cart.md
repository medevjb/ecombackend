# Customer addresses and cart

Saved addresses belong to one customer. Address routes identify records by public ULID and return 404 for another customer's record. The default selection is optional and changes under the customer row lock.

Guest carts use the `X-Cart-Token` response and request header. The API stores a hash of the token. A customer bearer token selects that customer's cart. A supplied but invalid bearer token is rejected. A guest token is consumed on merge and cannot be used to retrieve the merged cart.

Send the guest token during shared customer login to merge automatically, or call `POST /api/v1/cart/merge` after authentication. The customer and guest quantities combine. The service caps the result to stock and limits, and returns per-variant conflicts. The limit is 99 units per variant and 100 lines per cart.

Cart totals are recalculated from current catalog prices and available stock. Unavailable lines are retained as conflicts on read, with no payable line amount. Cart edits do not create reservations. Checkout will revalidate the owned address, cart, stock, and totals before reserving anything.

The [Postman environment](../api/local.postman_environment.json) captures guest tokens automatically. Set `use_customer_cart` to `true` after signing in to send the saved customer bearer token on optional-auth cart endpoints.
