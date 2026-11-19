# Customer and staff identity

The shared `users` table is the unique credential directory. Customer records use that account directly, while staff authenticate as a distinct `staff_users` principal linked one-to-one by the existing internal ID. This preserves Tyro's role pivot and audit history, keeps a single normalized login email across both identity types, and lets Sanctum enforce the principal type on private routes.

The staff migration backfills accounts with any existing Tyro role other than `customer` or `user`. Those staff accounts must sign in again after the migration to obtain a staff bearer token. Existing Tyro assignments and password hashes are preserved. Future staff provisioning must create the staff principal as well as the Tyro assignments.

## Phase 1 handoff

- F01.1 adds `users.public_id`, customer registration, display-name updates, password changes and recovery, and signed email verification. Verification is optional for login. Reset emails link to the storefront and are queued. Password changes and resets revoke existing tokens.
- F01.2 adds `staff_users`, distinct bearer guards, staff self-profile, staff password recovery and logout. Shared login returns `identity_type` and `dashboard` with the account and roles under `data`.
- F01.3 adds `two_factor_credentials` and `staff_login_challenges`. Enrollment expires after ten minutes. Sign-in challenges expire after five minutes, and each new challenge replaces the previous one for that staff account. Recovery codes are displayed when created and consumed once. Confirming enrollment and disabling TOTP require signing in again. Codes use the [RFC 6238 TOTP algorithm](https://www.rfc-editor.org/rfc/rfc6238).

Set the storefront and admin origins to the clients that handle the email reset link at `/reset-password`. Each client submits the email, token, and new password to its own API reset endpoint. Serve the API at the configured `APP_URL` so the signed email verification callback is reachable. A worker must be running for queued reset emails to reach Mailpit.

Example shared sign-in:

```sh
curl -X POST "$API_BASE/api/v1/auth/login" \
  -H 'Content-Type: application/json' \
  --data '{"email":"account@example.test","password":"<password>"}'
```

An HTTP 200 response supplies `data.access_token`. On HTTP 202, send `data.challenge_token` and an authenticator or recovery code to `POST /api/v1/admin/auth/2fa/challenge` before attempting staff API requests.
