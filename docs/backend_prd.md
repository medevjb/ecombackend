# Backend Product Requirements Document (PRD)

**Project:** SHOP.CO-inspired Single-Vendor Fashion E-Commerce Platform  
**Document ID:** BE-PRD-001  
**Version:** 1.0 — Implementation baseline  
**Date:** 2026-11-19  
**Status:** Owner approved the A-01–A-14 defaults, public ULIDs, Tyro RBAC, and one shared login. See [decision record](decisions/0001-api-foundation.md).  
**Implementation order:** Feature-by-feature, module-by-module; backend first  
**Source of truth:** Original developer test brief supplied by the project owner, four storefront Figma screenshots, and subsequent architecture decisions.

> **Agent instruction:** This PRD is an executable *planning contract*, not permission to improvise. Complete modules in the order given. Do not skip migrations, authorization, tests, OpenAPI definitions, or seeders. When a detail is marked **ASSUMPTION**, implement the stated default only after the owner approves the baseline or explicitly accepts the defaults. Record any change in `docs/decisions/`. Do not silently add business features or replace MVC + Services with Domain/DDD/Repository architecture.

---

## 1. Scope, provenance and non-negotiable decisions

### 1.1 What the supplied brief explicitly requires

- Laravel **12 or 13**, Laravel Sanctum, MySQL 8, REST JSON APIs, Next.js 14+ App Router + TypeScript frontend, Tailwind/shadcn, Stripe **Test Mode** (cards only).
- Customer registration, one login for every role, isolated customer and staff authorization, password reset, login throttling after five failed attempts; optional email verification.
- Role/permission system (Super Admin, Admin, Product Manager, Order Manager, Support), multiple roles per staff user, custom roles and permission matrix, permission middleware, protected Super Admin.
- Products, multiple images, SKU, category hierarchy (minimum two levels), price/sale price, stock, activation; public search, category, price filters, sorting, pagination, related products.
- Persistent customer cart, quantity edits, coupon redemption, checkout shipping address, Stripe test payments, order history, status emails.
- Lifecycle `pending → paid → processing → shipped → delivered`, with `cancelled`; stock deducted **only on payment**, restored for eligible cancellations; no overselling under concurrent checkout; human-readable order numbers.
- Admin dashboard, product/category/order/coupon/customer/RBAC/profile operations, seeded data, documentation, security, and a meaningful Git history.
- Bonuses: admin TOTP 2FA, tests, Docker, guest-cart merge, product reviews/ratings, live demo.

### 1.2 Additional owner requirements

- **Standard Laravel MVC + Service Layer**, **no `app/Domain` folder**, no mandatory Repository pattern or full DDD.
- Centralized, properly normalized database and consistent API v1 conventions.
- Reusable global API response trait/helper and centralized error handling.
- Standardized CRUD features for appropriate admin resources: search, filtering, sort/order, pagination, status transitions, soft delete/restore, bulk actions, audit attribution.
- Figma-driven homepage content and marketing modules, prepared for a clean frontend API integration.
- Implement backend **module by module**; finish and verify each feature before proceeding.

### 1.3 Figma observations (screenshots actually supplied)

- Announcement bar and signup CTA; main nav with Shop, On Sale, New Arrivals, Brands, search, cart, account.
- Homepage hero with headline, CTA, three statistics, brand logo strip.
- **New Arrivals** and **Top Selling** grids: product photo, name, star rating, price, old price, discount badge, View All.
- **Browse by Dress Style** tiles: Casual, Formal, Party, Gym.
- Customer testimonials, newsletter signup, footer columns, social links and payment-brand icons.
- The brief also refers to category/filter, product detail, cart and mobile Figma screens; **these particular screens were not attached here**. API requirements for them come from the written brief, not invented visual specifics.

### 1.4 Hard boundaries

- One retailer, one logical store; **not** a multivendor marketplace.
- Frontend consumes Laravel **only via APIs**; no Blade storefront/admin.
- No production card charges; Stripe test keys and verified webhook handling only.
- No hardcoded role-name checks as the primary authorization mechanism.
- No generic delete/status API for orders, payments, ledger entries, webhook events, or audit logs.
- No trusting client-supplied totals, prices, discounts, permissions, or paid status.
- Use feature flags/configuration for optional capabilities rather than incomplete pseudo-implementations.

### 1.5 Owner-approved defaults

| ID | Open choice | Proposed default | Impact if changed |
|---|---|---|---|
| A-01 | Checkout identity | Guest browsing/cart; authenticated checkout | Auth, checkout API |
| A-02 | Store currency | USD only for test/demo, integer cents | Pricing, Stripe |
| A-03 | Taxes | Configurable tax policy, zero for demo | Totals, order snapshot |
| A-04 | Shipping | Configurable flat rate, one shipping address per order | Checkout, shipping |
| A-05 | Inventory | One logical location; reservation + paid deduction | Inventory schema |
| A-06 | Product options | Simple product plus optional size/color variants | Catalog, cart |
| A-07 | Staff identities | `users` for customers, `staff_users` for staff | Guards, migrations |
| A-08 | Cancellation | Pending orders cancellable; paid unfulfilled orders require controlled refund and stock return | Orders/refunds |
| A-09 | Refunds | Stripe test full and partial refunds, authorized admin only | Payments |
| A-10 | Reviews | Authenticated, one approved review per purchased product per customer (editable), moderation | Reviews |
| A-11 | Media | Local disk in dev, S3-compatible adapter in production | Uploads |
| A-12 | CMS | Admin-managed hero, banners, navigation, brands, styles, testimonials, newsletter | Content API |
| A-13 | Sale price | `sale_price` applies only when valid and lower than regular price; discount % derived | Pricing |
| A-14 | Super Admin | Immutable seeded system role; cannot be changed/deleted by other users | RBAC |

**Resolution rule:** If owner does not approve an assumption, pause only the affected module, document the blocker, and proceed with independent modules. Never guess a monetary or fulfillment policy.

---

## 2. Objectives, actors, and success criteria

### 2.1 Product goals

1. Demonstrate complete customer purchase and staff administration workflows.
2. Make backend APIs stable enough for a separate Next.js integration phase.
3. Preserve financial, order, and inventory correctness during retries and concurrency.
4. Keep architecture understandable during live code review and easy to extend.
5. Implement bonus features without compromising core reliability.

### 2.2 Actors

- **Guest:** public catalog/CMS/search, guest cart, newsletter.
- **Customer:** authentication, own profile/address/cart/checkout/orders/reviews.
- **Support:** limited customer/order visibility and support actions.
- **Product Manager:** catalog, categories, variants, media, stock operations.
- **Order Manager:** orders, shipment status, authorized cancellations/refunds.
- **Admin:** delegated operational administration.
- **Super Admin:** role/permission and staff management, protected system access.
- **Stripe:** trusted external event sender **only after signature verification**.
- **Queue worker/scheduler:** background jobs, expiration, notifications, reconciliation.

### 2.3 Definition of project completion

All mandatory and selected bonus features pass automated tests, database migrations/seeders work on clean setup, OpenAPI is accurate, staff/customer permissions are enforced, Stripe test payment succeeds and fails correctly, no overselling occurs in race tests, and Next.js can consume all storefront/admin flows without undocumented workarounds.

---

## 3. Architecture and coding contract

### 3.1 Pattern: MVC + Services

- **Controller:** thin HTTP adapter; call Form Request, policy, Service, API Resource/response helper.
- **Form Request:** validation, normalized input, permission where suitable.
- **Model:** Eloquent persistence, casts, relations, query scopes; no external API calls.
- **Service:** reusable business use cases, DB transactions, domain rules, coordination.
- **Policy/middleware:** authorization and authentication.
- **API Resource:** output allowlist and consistent serialization.
- **Trait/response factory:** standardized JSON envelope; do not hide HTTP behavior.
- **Job/event/listener:** asynchronous side effects, after-commit dispatch.
- **Enum:** controlled statuses and type values.
- **Exception:** explicit business error code and HTTP mapping.

Do **not** add a Repository layer, `Domain` folder, event-sourcing platform, or microservices without a documented, approved need. Avoid fat controllers and overly generic `BaseService` classes.

### 3.2 Approved folder structure

```text
app/
├── Http/
│   ├── Controllers/Api/V1/{Auth,Storefront,Customer,Admin,Webhooks}/
│   ├── Requests/Api/V1/{Auth,Storefront,Customer,Admin}/
│   ├── Resources/Api/V1/
│   └── Middleware/
├── Models/
├── Services/{Auth,Catalog,Inventory,Cart,Checkout,Orders,Payments,Promotions,Content,Reporting}/
├── Traits/{ApiResponse,HasStatus}.php
├── Enums/
├── Exceptions/
├── Policies/
├── Jobs/
├── Events/
├── Listeners/
├── Notifications/
└── Providers/
routes/
├── api.php
└── api/v1/{auth,storefront,customer,admin,webhooks}.php
database/{migrations,factories,seeders}/
tests/{Unit,Feature,Integration}/
docs/{prd,srs,api,database,architecture,decisions,testing}/
```

Directories are added when needed, not prepopulated with empty placeholder classes. Namespaces follow PSR-4. Laravel bootstrap/providers and route registration must follow the actual installed Laravel version.

### 3.3 Cross-cutting technical standards

- MySQL 8/InnoDB, `utf8mb4`, strict SQL mode, FK constraints, unique keys, indexes, UTC timestamps.
- Financial amounts stored as **integer minor units** (`*_amount` BIGINT) and ISO currency code. No floats for money.
- Stock quantities stored as nonnegative integers; reject fractional inventory.
- Server-controlled created/updated timestamps; soft deletes only where allowed.
- Explicit fillable/guarded policy; never mass-assign sensitive fields.
- Stable ID policy: bigint internal PK; public resources use ULIDs consistently. Avoid leaking secrets via IDs; ownership always enforced.
- Pagination default 20, maximum 100; deterministic secondary sort by primary key.
- Filters and sort fields must be allowlisted; no arbitrary column/operator from query string.
- Rate limits per IP and identity for auth, checkout, coupons, reviews, and public search.
- Use Redis for cache/queue in production profile; database queue acceptable for local fallback.
- Structured logs with request/correlation ID; redact secrets, tokens, PII and Stripe payload details.
- External effects must occur after DB commit; use queued jobs with retries/backoff and deduplication.

### 3.4 API envelope

Success:

```json
{
  "success": true,
  "message": "Products retrieved successfully.",
  "data": [],
  "meta": {"pagination": {"current_page": 1, "per_page": 20, "total": 0, "last_page": 1}},
  "errors": null,
  "request_id": "req_..."
}
```

Failure:

```json
{
  "success": false,
  "message": "Insufficient stock.",
  "data": null,
  "meta": null,
  "errors": {"code": "INSUFFICIENT_STOCK", "fields": {"quantity": ["Only 2 units are available."]}},
  "request_id": "req_..."
}
```

- Standard codes: 200 read/update, 201 create, 202 accepted async, 204 no-content **with no body**, 400 malformed, 401 unauthenticated, 403 unauthorized, 404 absent/hidden, 409 conflict, 422 validation/business validation, 429 throttled, 500 unexpected.
- Errors must use stable machine-readable `errors.code`, safe `message`, optional per-field errors. No stack trace in production.
- Global exception rendering covers validation, auth, authorization, model-not-found, throttling, business conflicts, unexpected errors.
- `ApiResponse` trait may delegate to a `ResponseFactory`; use API Resources for serialization. Do not duplicate response JSON in controllers.
- Public endpoints never expose private notes, password hashes, secret keys, internal fraud signals, or staff-only data.
- OpenAPI 3.x is the canonical API contract; examples, errors, schemas, auth, query params and pagination documented **per endpoint**. Provide generated interactive docs and Postman collection.

### 3.5 Standard admin CRUD contract

For **eligible resources** (products, categories, brands, banners, homepage sections, coupons, navigation, staff roles where safe):

- List: `search`, `status`, allowed filters, `sort_by`, `sort_direction`, `page`, `per_page`.
- Create: validated fields; unique slugs/SKUs as appropriate; `created_by` from authenticated staff.
- Detail: explicit API Resource.
- Update: PATCH with partial validation; `updated_by` from staff.
- Status: dedicated `PATCH /{resource}/{id}/status` where useful; `active/inactive` only when meaningful; product may also have `draft/archived` publication lifecycle.
- Bulk: `POST /{resource}/bulk-actions` with `{ "action": "activate|deactivate|delete|restore", "ids": [...] }` and bounded max batch (100). Per-item authorization; partial success response with `succeeded_ids`, `failed` entries, and counts; **never** silently bypass invariants.
- Ordering: `PATCH /{resource}/reorder` with ordered IDs and optional parent/group; validate completeness/ownership; transactional.
- Soft delete/restore for eligible content; no physical deletion if historical references exist.
- Audit: actor, operation, target, timestamp, changed-field names, safe old/new values where appropriate.
- Explicitly **excluded** from generic CRUD: orders, order items, payments, refunds, inventory movements, reservations, webhook receipts, audit logs. Use state-machine commands and append-only records.

---

## 4. Database model blueprint

This is a **logical schema contract**, not a substitute for the later column-by-column ERD. Agents must design exact migrations, FKs, indexes and uniqueness before implementing each module; review migrations before running them.

| Area | Tables | Key invariants |
|---|---|---|
| Identity | `users`, `staff_users`, `password_reset_tokens`, `personal_access_tokens`, `sessions`, `two_factor_credentials` | Separate guards/providers; normalized login emails unique across both identity types; secrets encrypted |
| RBAC | Tyro `roles`, `privileges`, `user_roles`, `privilege_role` | Guard-specific permissions; multiple staff roles; protected Super Admin |
| Catalog | `products`, `product_variants`, `product_images`, `categories`, `category_product`, `brands` | Unique variant SKU, unique slug in scope, no cyclic categories |
| Attributes | `attributes`, `attribute_values`, `variant_attribute_values` | Unique option combinations per product |
| Inventory | `inventories`, `inventory_movements`, `stock_reservations` | Nonnegative available-to-sell; unique movement event; no double restoration |
| Cart | `carts`, `cart_items` | One current cart per customer or guest token; unique variant per cart |
| Promotions | `coupons`, `coupon_redemptions`, `coupon_product`, `coupon_category` | Redemption count bounded, unique redemption per qualifying checkout |
| Checkout | `checkout_attempts`, `idempotency_keys` | Unique idempotency per actor+operation+key; expiry |
| Orders | `orders`, `order_items`, `order_addresses`, `order_status_histories` | Unique order number, immutable price/address snapshots |
| Payments | `payments`, `payment_events`, `refunds`, `webhook_events` | Unique Stripe intent/event IDs, immutable provider history |
| Customer | `customer_addresses`, `product_reviews` | Ownership enforced; review moderation |
| CMS | `banners`, `homepage_sections`, `homepage_section_items`, `navigation_items`, `site_settings`, `testimonials` | Published ordering; safe link targets |
| Marketing | `newsletter_subscribers` | Unique normalized email; opt-out state |
| Ops | `activity_logs`, `jobs`, `failed_jobs`, `notifications`, `media` | Audit visibility restricted; failed jobs inspectable |

**Cross-cutting columns:** appropriate `status`, `sort_order`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`; do **not** add irrelevant `status` or `sort_order` columns to immutable ledger records.

**Mandatory migration review:** FK delete actions; composite unique indexes; order-item product snapshots; reservation expiry; monetary type; indexes for `(status, created_at)`, customer orders, category/product visibility, inventory variant, webhook IDs, Stripe IDs; consistent tokenable key types for Sanctum; check constraints where supported.

---

## 5. Incremental delivery protocol (mandatory for every module)

Each phase is a **vertical backend feature slice**, not a pile of models followed by all controllers at the end.

**Execution checklist for every feature:**

1. Read PRD feature ID and dependencies; write/confirm acceptance tests first.
2. Define migration/schema and constraints; add model relations/casts/scopes and factory.
3. Implement Form Request, Policy/permission, Service business rules, Controller, API Resource, versioned routes.
4. Add success/error examples and OpenAPI schema before marking the feature done.
5. Add unit/feature/integration tests, including negative authorization and validation cases.
6. Add seeders/fixtures where needed; run migrations and test suite on a fresh DB.
7. Verify HTTP codes, response envelope, pagination, audit logs, and no N+1 queries.
8. Commit only completed, tested feature with meaningful commit message.
9. Produce module completion note: endpoints, tables, tests, decisions, blockers, example curl.
10. **Gate:** do not start the next dependent feature until the current feature passes.

**Global gates:** migrations fresh/rollback; formatting/static analysis; all existing tests; API contract validation; security checks; README updates. No TODO stubs presented as complete functionality.

---

## 6. Phase 0 — Foundation and API standards

**Goal:** Bootable backend with reliable conventions before business features.

### F00.1 Application setup
- Initialize Laravel 12 (or 13 only after confirming compatibility), PHP/runtime requirements, MySQL 8, Sanctum, Stripe SDK, Mailpit, queue and storage drivers.
- `.env.example`, config validation, local setup instructions, `.gitignore`, safe CORS, separate frontend/admin origins, health endpoint.
- Docker Compose for app, MySQL, Redis, Mailpit and worker; no production credentials in source.

### F00.2 API response and error handling
- Build `ApiResponse` trait and central response factory, request IDs, exception mapping, validation errors, consistent resources and pagination.
- API v1 routing and naming; 404 and 405 for API routes return documented JSON; 204 remains empty.

### F00.3 Shared admin patterns
- Reusable validated query filter/sort/pagination utilities, safe status handling, bulk-operation result DTO/response, audit attribution; avoid generic controllers that bypass policy checks.
- Define conventions for `created_by`, `updated_by`, `sort_order`, soft delete/restore and status enums.

### F00.4 Quality toolchain
- Pint, PHPStan/Larastan, PHPUnit/Pest, CI, OpenAPI generation/validation, seed conventions, structured logs.

**Exit tests:** boot/health, standardized success/validation/not-found/exception JSON, page-limit enforcement, API v1 routing, fresh migrations, CI green. **Deliverables:** setup README, architecture ADR, API conventions document.

---

## 7. Phase 1 — Customer identity and staff authentication

**Dependencies:** Phase 0.

### F01.1 Customer account
- Register with unique normalized email, strong password, bcrypt, validated name; login/logout; current user/profile update; change password.
- Password reset via Mailpit with expiry and single-use semantics; optional email verification implemented as bonus.
- Rate limit after five failed attempts in defined window; avoid account enumeration.

**Endpoints:** `POST /auth/register`, `/auth/login`, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password`; `GET/PATCH /me`; `POST /auth/email/verification-notification`, `GET /auth/email/verify/{id}/{hash}` (signed flow or API-compatible equivalent).

### F01.2 Staff identity
- Use a separate `staff_users` provider and staff guard. Customer and staff credentials resolve through the same `POST /auth/login` endpoint. Resolve identity server-side using the globally unique normalized email. Login returns the bearer token, identity type, role slugs, and a server-selected dashboard audience (`staff` or `customer`). The frontend routes to the appropriate dashboard using this audience. Role and privilege checks are enforced on the API.
- Sanctum tokens with scoped abilities, revocation, expiry policy; first-party browser cookie authentication if deployment topology supports it; document chosen client strategy.
- Customer tokens must not authorize staff routes even if IDs overlap.

**Endpoints:** Shared `POST /auth/login`. Staff-only `POST /admin/auth/logout`, `/admin/auth/forgot-password`, `/admin/auth/reset-password`; `GET/PATCH /admin/me`.

### F01.3 Admin TOTP 2FA (bonus, included)
- Setup: authenticated staff verifies password, generates encrypted secret and recovery codes, confirms TOTP before activation.
- The shared login returns a short-lived second-factor challenge for staff accounts with active TOTP. Issue a full token only after verification.
- Disable/reset require elevated verification and audit; recovery codes one-time and hashed.

**Endpoints:** `POST /admin/auth/2fa/setup`, `/confirm`, `/challenge`, `/disable`, `/recovery` (precise schema in OpenAPI).

**Exit tests:** register/login/logout/reset/verification, five-attempt throttle, cross-guard isolation, revoked token, wrong TOTP/replay/recovery, generic login failure. **Gate:** no staff feature until guard isolation passes.

---

## 8. Phase 2 — Staff RBAC and audit trail

**Dependencies:** Phase 1.

### F02.1 Permissions and roles
- Use Tyro roles and privileges for the staff identity provider. Retain staff guards and policies as authorization boundaries. Migrate existing local staff identities and Tyro assignments while preserving the Super Admin.
- Seed five default roles; map granular permissions using `resource.action` (e.g. `products.view`, `products.create`, `products.update`, `products.delete`, `orders.view`, `orders.update_status`, `coupons.manage`, `reports.view`).
- Multiple roles per staff account; effective permissions returned in admin `/me` response for frontend visibility only.
- Super Admin has full authorization via guarded Gate policy; protected seeded role cannot be modified/deleted by others; prevent last Super Admin lockout.

### F02.2 Role and staff administration
- Custom role create/update/permissions matrix; staff invite/create, assign/remove roles, activate/deactivate; staff self-profile.
- Permission changes invalidate relevant cached permissions; critical security changes revoke sessions if policy requires.
- All staff management endpoints require appropriate permissions; no arbitrary privilege escalation.

**Endpoints:** `GET /admin/permissions`, `GET/POST /admin/roles`, `GET/PATCH/DELETE /admin/roles/{id}`, `PUT /admin/roles/{id}/permissions`, `GET/POST /admin/staff`, `GET/PATCH /admin/staff/{id}`, `PUT /admin/staff/{id}/roles`.

### F02.3 Audit trail
- Log admin create/update/status/bulk, role/permission changes, inventory adjustments, order status changes and refunds; store actor, target, timestamp, safe before/after details, request ID.
- Read-only, paginated, filtered audit endpoint; never offer bulk deletion.

**Exit tests:** permission matrix, multiple roles, missing permission 403, protected role, last Super Admin, staff guard isolation, audit entry creation, pagination/filtering.

---

## 9. Phase 3 — Catalog foundation: categories, brands, attributes

**Dependencies:** Phase 2.

### F03.1 Categories
- Parent/child tree with >=2 levels; name, slug, description, image, status, sort order, SEO fields.
- Reject self-parenting/cycles; category deactivation hides descendants from public navigation as defined in API contract.
- Admin CRUD, status, bulk, reorder, restore; public category tree and detail/list endpoints.

### F03.2 Brands
- Name, slug, logo, description, status, sort order; admin CRUD/bulk/reorder; public active brands.
- Brand logos feed Figma brand strip.

### F03.3 Attributes
- Size, color and future attributes; values, display labels, ordering; manage allowed option values; no duplicate value per attribute.
- Attributes are reusable; variant combinations must be unique per product.

**Endpoints:** public `GET /categories`, `/categories/{slug}`, `/brands`, `/attributes`; admin `/admin/categories`, `/admin/brands`, `/admin/attributes` and nested attribute values, plus resource-specific status/bulk/reorder endpoints.

**Exit tests:** tree/cycle detection, slug uniqueness, hidden inactive data, status/bulk/restore, reorder, permission checks, correct public shape.

---

## 10. Phase 4 — Products, variants, media and discovery

**Dependencies:** Phase 3.

### F04.1 Product management
- Product fields: name, slug, description, short description, brand, category assignments, regular price, optional sale price, publication status (`draft`, `active`, `inactive`, `archived`), featured flag, SEO fields.
- Product must have at least one category before publication. Price nonnegative, sale price below regular price when present; discount badge derived from price, not separately editable.
- Primary SKU belongs to sellable variant; simple products use one default variant.
- Soft delete hides product publicly; retain historical order snapshots.

### F04.2 Variants
- Optional size/color combinations; unique SKU and combination; optional price override; variant activation; stock managed only by Inventory service.
- Prevent deletion of variants referenced by historical order items; archive instead.

### F04.3 Media
- Multiple product images, alt text, primary flag, sort order; validate MIME, dimensions and size; generate safe paths; configurable storage adapter.
- Delete/reorder/replace operations enforce ownership and never break order history; async thumbnail generation optional.

### F04.4 Public catalog
- `GET /products`: search, category, brand, attributes, min/max price, in-stock, sort by price/newest/top-selling, paginated.
- `GET /products/{slug}`: gallery, variants, stock availability, description, price/sale/discount, average rating/count, related products.
- Only active products, active variants, visible categories and eligible images exposed publicly.
- Figma homepage uses New Arrivals and Top Selling. New Arrivals sorted by publication time; Top Selling ranked by completed eligible paid item quantity over documented time window (default all time) and stable tie-breaker.

**Endpoints:** public `GET /products`, `/products/{slug}`, `/products/{slug}/related`, `/collections/new-arrivals`, `/collections/top-selling`; admin `/admin/products`, `/admin/products/{id}`, nested `/variants`, `/images`, plus bulk/status/reorder where appropriate.

**Exit tests:** filters combined, sort whitelist, variant uniqueness, media primary uniqueness, unauthorized edits, inactive visibility, pagination, N+1 avoidance, price math.

---

## 11. Phase 5 — Inventory management and concurrency primitives

**Dependencies:** Phase 4. **Payment/checkout cannot start until this phase passes.**

### F05.1 Inventory state
- Each sellable variant has on-hand quantity and reserved quantity (or mathematically equivalent reservation ledger).
- **Available-to-sell = on-hand − active reserved**, never below zero.
- Physical on-hand quantity decreases **only when payment is confirmed**; reserving stock does not decrement on-hand.
- Inventory adjustment service accepts reason, delta, actor, idempotency key; log immutable movements; forbid direct admin PATCH to quantity.

### F05.2 Reservation lifecycle
- Create reservation atomically using `SELECT ... FOR UPDATE` in consistent variant-ID order; enforce available-to-sell.
- Reservation statuses: active, committed, released, expired. Expiration is idempotent, controlled by scheduler.
- Reservation expiry must not invalidate an already-confirmed payment without reconciliation; if a late payment arrives, safely re-check stock, attempt to fulfill or issue refund/exception workflow; **never** mark an unfulfillable paid purchase as fulfilled.
- No database lock may be held during Stripe network calls.

### F05.3 Low-stock reporting
- Configurable low-stock threshold; list/filter inventory; low-stock dashboard count; optional queued alert.

**Endpoints:** `GET /admin/inventory`, `GET /admin/inventory/movements`, `POST /admin/inventory/adjustments`; no public inventory quantity mutation.

**Exit tests:** two concurrent reservations for last unit, expiration/retry, concurrent adjustments, never-negative availability, committed-only-on-payment, exactly-once restoration. **Gate:** concurrency integration test required.

---

## 12. Phase 6 — Customer addresses and cart

**Dependencies:** Phases 1, 4, 5.

### F06.1 Address book
- Customer owns saved shipping addresses; validate recipient, line 1/2, city, region, postal code, country, phone as configured; default shipping address optional.
- Never leak other customers' addresses; order address will be snapshotted at checkout.

### F06.2 Guest and authenticated cart
- Guest cart identified by opaque signed/unguessable cookie/token; server persists cart; login merges with customer cart.
- Add variant and quantity; update/remove/clear; prevent out-of-stock items being added; validate quantity limits and active product/variant.
- Cart does not reserve inventory; cart prices and availability are re-evaluated on every read and checkout.
- Merge rule: same variant quantities combine but cap/reject according to stock and return structured conflict details; remove stale items safely.
- Totals are estimates until checkout; only server calculates price.

**Endpoints:** `GET /cart`, `POST /cart/items`, `PATCH /cart/items/{id}`, `DELETE /cart/items/{id}`, `DELETE /cart/items`, `POST /cart/merge`; customer `GET/POST /me/addresses`, `PATCH/DELETE /me/addresses/{id}`.

**Exit tests:** guest persistence, merge on login, duplicate line consolidation, invalid quantity, unavailable variant, cart ownership, stale price updates, unauthorized address access.

---

## 13. Phase 7 — Promotions and coupons

**Dependencies:** Phases 2, 4, 6.

### F07.1 Coupon admin
- Code (case-normalized, unique), type (`percentage`, `fixed`), value, currency for fixed, min order amount, start/end, active/inactive, global usage limit, per-customer limit, applicable products/categories.
- Percentage 0 < x <= 100; fixed > 0; dates valid; never discount more than eligible subtotal; document whether shipping is discountable (default **no**).
- Admin CRUD, status, bulk activation/deactivation, list filters; soft delete where safe.

### F07.2 Coupon application
- Apply/remove coupon to cart as provisional estimate; validate again at checkout and finalization; reserve/redeem coupon use atomically with successful order payment to prevent overuse.
- Expired/ineligible/exhausted coupon yields stable error code; protect against coupon enumeration/brute force.
- Redemption ledger ties coupon, customer and order; canceled/refunded redemption reversal policy **ASSUMPTION: do not restore global usage automatically unless explicitly configured**.

**Endpoints:** `POST /cart/coupon`, `DELETE /cart/coupon`, admin `/admin/coupons` and status/bulk endpoints.

**Exit tests:** expiry, limit race, per-user limit, minimum spend, restricted products, fixed/percentage math, tampered coupon/price, repeated checkout idempotency.

---

## 14. Phase 8 — Checkout, Stripe payments, orders (critical path)

**Dependencies:** Phases 1, 5, 6, 7. **Implement in the subfeature order below.**

### F08.1 Checkout preview
- Server recomputes every line from product/variant price, quantity, availability, coupon, shipping and tax policy.
- Return subtotal, discount, shipping, tax, grand total, currency, address summary, line snapshots, and any stock/coupon conflicts.
- Reject client-submitted monetary totals or ignore them completely; document policy.

**Endpoint:** `POST /checkout/preview`.

### F08.2 Checkout attempt + reservation
- Accept selected address/shipping data and idempotency key; lock inventory rows in consistent order; validate stock; create reservation and `checkout_attempt` with expiry.
- Create a **pending order** with immutable line/address snapshots before payment (allowed by brief: never mark paid before Stripe confirmation); create pending payment record.
- Human-readable `ORD-YYYY-NNNNNN` number unique using transaction-safe sequence/counter; no `MAX(id)+1` race.
- Return order number, checkout attempt ID, Stripe client secret (only for that attempt), and expiration.
- Repeat same idempotency key returns same logical attempt; different payload with same key returns conflict.

**Endpoint:** `POST /checkout`.

### F08.3 Stripe PaymentIntent
- Create Stripe PaymentIntent server-side using canonical amount/currency and idempotency key; never accept client-supplied Stripe payment status.
- Handle failed API requests and retries without duplicate orders or intents; no DB transaction held open across Stripe call.
- Use PaymentIntent ID unique constraint and durable attempt/payment correlation.

### F08.4 Stripe webhook settlement
- Raw webhook endpoint verifies Stripe signature and stores provider event ID uniquely before processing; duplicate events return safe success without repeating effects.
- Queue event processing after durable receipt; process out-of-order events by querying current provider state where necessary.
- On verified successful payment: check expected amount/currency/attempt, atomically commit reservation, decrement on-hand, append movement, mark payment paid, mark order paid, commit coupon redemption, queue notification after commit.
- On failed/cancelled intent: mark attempt/payment appropriately and release reservation; pending order remains unpaid/cancelled as defined by state machine.
- If payment succeeds after reservation expiry and stock cannot be committed: mark `payment_exception`, do not claim order fulfilled, initiate safe refund/reconciliation and notify operations. No overselling or silent lost charge.

**Endpoint:** `POST /webhooks/stripe` (signature-verified, no user auth; rate limits and body size controls).

### F08.5 Order state machine

| Current | Allowed next | Actor/precondition |
|---|---|---|
| pending | paid | Verified payment finalization only |
| pending | cancelled | Customer or authorized staff; no completed charge |
| paid | processing | Authorized order staff |
| processing | shipped | Authorized staff; shipment details validated |
| shipped | delivered | Authorized staff; delivery confirmation |
| paid / processing | cancelled | Authorized cancellation workflow and refund policy; no shipment |
| shipped / delivered | refund workflow | Not direct cancellation; separate refund/return policy |

- Reject arbitrary `PATCH status` or illegal transitions; every transition logs actor/time/from/to/reason and queues customer email.
- Payment state (`pending`, `succeeded`, `failed`, `refunded`, `partially_refunded`) and fulfillment state must not be conflated with order status.
- Staff/customer ownership: customer only sees own orders; staff only with permission.

**Endpoints:** `GET /orders`, `GET /orders/{id}`, `POST /orders/{id}/cancel`; admin `GET /admin/orders`, `GET /admin/orders/{id}`, `POST /admin/orders/{id}/transitions`.

### F08.6 Cancellation and refunds
- Pending unpaid cancellation releases reservation, no stock restoration because no stock was deducted.
- Paid unshipped cancellation triggers authorized Stripe refund workflow; order cancellation and inventory restoration happen exactly once according to explicit refund-success policy.
- Support partial/full refunds as bonus; track refund records, reason, provider ID, amount, currency, status; partial refunds do **not** automatically restock unless a corresponding item-level return/restock decision is recorded.
- Failed refund remains `refund_pending`/`refund_failed` and visible to staff; no false success.

**Endpoints:** `POST /admin/orders/{id}/cancel`, `POST /admin/orders/{id}/refunds`, `GET /admin/orders/{id}/refunds`.

### F08.7 Notifications
- Mailpit demo emails for payment, processing, shipped, delivered, cancellation, refund outcomes.
- Queue after commit; retryable and deduplicated by order+transition/event ID; email failures must not roll back paid order.

**Exit tests (non-negotiable):**
1. Two buyers compete for final unit; only one can finalize inventory.
2. Duplicate checkout request with same key creates one order/intent.
3. Duplicate/out-of-order Stripe webhooks do not double-charge/deduct/redeem.
4. Failed payment never creates `paid` order.
5. Payment success after reservation expiration triggers reconciliation, not oversell.
6. Tampered client totals ignored/rejected; expired coupon blocked.
7. Illegal state transition rejected.
8. Paid cancellation/refund stock restored at most once.
9. Customer cannot access another customer's order.
10. Order snapshots remain unchanged after product/price/address edits.

**Gate:** do not proceed to dashboard until these integration tests are green.

---

## 15. Phase 9 — Admin customer management and reporting

**Dependencies:** Phases 2, 5, 8.

### F09.1 Customers
- Paginated/searchable customer list; customer detail, verification/account status, order history; permission-gated.
- Do not allow admin to see passwords, tokens, TOTP data or full Stripe secret data.
- Account deactivation must not destroy order/payment history.

### F09.2 Dashboard
- Today's orders, today's revenue, customer count, low-stock count, 30-day sales series, recent orders.
- Define reporting timezone (default UTC until owner chooses store timezone).
- Define revenue: **net captured payments minus completed refunds** for selected period; order counts include all placed orders unless status filter specified. Provide separate gross/discount/refund metrics where useful.
- Cache expensive aggregates with explicit invalidation/TTL; filters validated and indexed.

### F09.3 Reports
- Sales by day/product/category, coupon use, inventory low-stock, order status distribution; server-side date range and pagination for detail reports.

**Endpoints:** `GET /admin/customers`, `/admin/customers/{id}`, `/admin/customers/{id}/orders`, `/admin/dashboard`, `/admin/reports/sales`, `/admin/reports/inventory`, `/admin/reports/coupons`.

**Exit tests:** permission scoping, revenue arithmetic with refunds, timezone boundaries, 30-day series, empty datasets, indexed queries.

---

## 16. Phase 10 — Storefront CMS and marketing

**Dependencies:** Phases 2, 3, 4.

### F10.1 Homepage content
- Announcement bar text/link/enabled state; hero slides with heading, copy, image, CTA, schedule, status, order.
- Homepage section definitions and items: New Arrivals, Top Selling, featured categories/collections, brand strip, dress-style tiles (Casual/Formal/Party/Gym), optional promotional cards.
- CMS may reference dynamic product collections or curated product IDs; document per-section strategy. Do not duplicate canonical product prices in CMS.
- Site stats in hero are editable marketing content and must not masquerade as live audited business metrics.

### F10.2 Navigation, footer and site settings
- Header links, footer columns, social URLs, brand logos, newsletter copy, SEO defaults, support contact; sanitize and validate URLs.
- Public endpoints return only published content and safe fields.

### F10.3 Newsletter
- Subscribe with normalized unique email; consent timestamp/source; unsubscribe token and suppression state; throttle signup; no duplicate contacts.
- No marketing emails sent without opt-in policy; Mailpit demo integration.

### F10.4 Testimonials
- Admin-managed testimonial records or approved customer review highlights; clear source field; no fabricated verified-buyer status.
- Status, ordering and optional customer attribution; public visible only when approved.

**Endpoints:** public `GET /storefront/home`, `/storefront/navigation`, `/storefront/settings`, `POST /newsletter/subscribe`, `POST /newsletter/unsubscribe`; admin `/admin/banners`, `/admin/homepage-sections`, `/admin/navigation`, `/admin/site-settings`, `/admin/testimonials`, `/admin/newsletter-subscribers`.

**Exit tests:** exact Figma homepage data needs met, hidden/unpublished content excluded, ordered sections, broken image fallback contract, URL validation, newsletter dedupe/opt-out.

---

## 17. Phase 11 — Product reviews and ratings (bonus)

**Dependencies:** Phases 1, 4, 8.

- Authenticated customers can submit/edit own review for eligible purchased product; configurable verified-purchase policy.
- Rating integer 1–5, optional title/body; moderation (`pending`, `approved`, `rejected`), soft deletion.
- Public listing and aggregate rating include approved reviews only; prevent fake rating injection.
- Admin review list, moderation and bulk approve/reject where safe; audit moderation.

**Endpoints:** `GET /products/{slug}/reviews`, `POST /products/{slug}/reviews`, `PATCH/DELETE /me/reviews/{id}`, `GET /admin/reviews`, `POST /admin/reviews/{id}/moderate`.

**Exit tests:** ownership, verified-purchase rule, one-review rule, moderation visibility, rating aggregate, unauthorized edits.

---

## 18. Phase 12 — Hardening, documentation and demo readiness

**Dependencies:** all preceding phases.

### F12.1 Security
- Validate every Form Request, explicit resource fields, secure CORS/CSRF strategy, rate limits, secure cookies/token storage policy, security headers, XSS-safe rich text, upload validation, IDOR regression tests.
- Review all admin routes against RBAC matrix; no direct mutation of paid state or inventory ledger.
- Secrets only in environment/secret manager; `APP_DEBUG=false` in production; redact logs.
- Run dependency vulnerability checks and review Stripe webhook signature verification.

### F12.2 Testing and performance
- Unit, feature, integration, race/concurrency, idempotency, authorization, payment failure/retry, database migration and seeder tests.
- Detect N+1, enforce pagination cap, indexes on hot queries; measure p95 ordinary reads under defined test load (target <300 ms excluding external calls, **proposed**).
- Ensure queue retries and scheduled expiration are observable.

### F12.3 Documentation and deployment
- Complete OpenAPI, Postman collection, API authentication guide, sample requests/errors, ERD, RBAC matrix, checkout state diagram, Stripe webhook local testing, `.env.example`, README, Docker Compose, CI, demo data, deployment notes.
- Include a 5-minute demo script: register → browse/filter → cart → coupon → Stripe success → order → staff status update; Stripe failure; permissions; inventory concurrency.
- Optional deploy to live demo URL; do not claim live deployment without verification.

**Exit tests:** clean clone + one-command setup, fresh migrations/seeders, test suite green, OpenAPI validates, demo flows reproducible, no secrets in repository, meaningful Git commits.

---

## 19. API route inventory and standards

All examples are relative to `/api/v1`. Final OpenAPI must specify body schemas, path/query params, authentication, authorization, success/errors, pagination, and idempotency.

| Consumer | Route families | Authorization |
|---|---|---|
| Public | `/products`, `/categories`, `/brands`, `/attributes`, `/collections/*`, `/storefront/*`, `/newsletter/*` | Public + throttles |
| Shared login | `POST /auth/login` | All identities, throttled |
| Customer auth | `/auth/register`, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password`, email verification | Guest/customer depending on action |
| Customer | `/me`, `/me/addresses`, `/cart`, `/checkout`, `/orders`, `/me/reviews` | Customer guard + ownership |
| Staff auth | `/admin/auth/*` (excluding login), `/admin/me` | Staff guard or staff challenge depending on action |
| Admin catalog | `/admin/products`, `/admin/categories`, `/admin/brands`, `/admin/attributes`, `/admin/inventory` | Staff guard + permissions |
| Admin commerce | `/admin/coupons`, `/admin/orders`, `/admin/customers`, `/admin/reviews` | Staff guard + permissions |
| Admin content | `/admin/banners`, `/admin/homepage-sections`, `/admin/navigation`, `/admin/site-settings`, `/admin/testimonials` | Staff guard + permissions |
| Admin security | `/admin/roles`, `/admin/permissions`, `/admin/staff`, `/admin/activity-logs` | Staff guard + elevated permissions |
| Admin analytics | `/admin/dashboard`, `/admin/reports/*` | Staff guard + reports permission |
| Stripe | `/webhooks/stripe` | Verified Stripe signature; idempotent |

**Response contract extras:** `ETag`/conditional GET optional; ISO-8601 UTC dates; currency code on all money; integer minor-unit amounts; `per_page <= 100`; `sort_by` allowlist; request ID on responses; stable error codes. Version changes must not silently break v1 clients.

**Frontend integration requirements (contract only, not frontend implementation now):** typed API client generated from OpenAPI where possible; centralized error handling keyed by `errors.code`; 401 logout/refresh policy; 403 permission UI; 422 field mapping; 409 conflict reconciliation; 429 retry hints; loading/empty states; safe cookie/token storage; server-side pagination/filter query mapping. No frontend code required in backend phases.

---

## 20. Security, privacy, reliability and operational rules

- **Authentication:** customer/staff guard separation; password reset token expiry; 2FA; generic credential failures; token revocation.
- **Authorization:** policy + permission middleware; ownership checks; no IDOR; protected Super Admin; effective permission API for UI only.
- **Payments:** Stripe test only; verified webhook; no card data stored; validate amount/currency; deduplicate events; idempotent refunds.
- **Inventory:** row locking, consistent lock order, reservation expiry, immutable movement ledger, no oversell; handle late successful payments with refund/reconciliation.
- **Financials:** integer minor units, no floats, canonical totals server-side, immutable order snapshots, no arbitrary payment-state mutation.
- **Files:** MIME/size validation, safe filenames, restricted uploads, no executable public upload.
- **Privacy:** minimum necessary PII, redact logs, staff access logs, newsletter consent/opt-out; retention policy to be defined before production.
- **Observability:** request ID, structured logs, queue failure alert, health/readiness, Stripe event reconciliation job, failed-job retry process.
- **Backup:** documented daily DB backup and restoration test for production deployment; no claim of backup until configured.
- **Resilience:** transactions limited to DB work, after-commit events, retries/backoff for email/provider calls, idempotency keys, scheduled cleanup.

---

## 21. Permissions matrix — baseline

Legend: **Y** full action within policy; **L** limited/read-only; **—** denied. Super Admin always subject to immutable system safeguards.

| Permission group | Super Admin | Admin | Product Manager | Order Manager | Support |
|---|---|---|---|---|---|
| Staff/roles/permissions manage | Y | L (delegated only) | — | — | — |
| Products/categories/brands manage | Y | Y | Y | L | L |
| Inventory adjust | Y | Y | Y | — | — |
| Coupons manage | Y | Y | — | L | L |
| Orders view | Y | Y | L | Y | L |
| Orders transition/cancel | Y | Y | — | Y | L (notes only) |
| Refund initiate | Y | Y | — | Y (policy-limited) | — |
| Customers view | Y | Y | — | Y | L |
| CMS/marketing manage | Y | Y | L | — | — |
| Reports view | Y | Y | L | Y | — |
| Audit logs view | Y | Y | — | L | — |

**Agent note:** This table is a *role-default proposal*, not a substitute for granular permission seed definitions. Produce explicit `permissions` list and route-to-permission map before Phase 2. Avoid ambiguous `L` in code; translate to concrete named permissions and policies.

---

## 22. Acceptance criteria traceability

| Requirement | Feature IDs | Evidence |
|---|---|---|
| Required tech stack and REST API | F00 | App boots; docs; CI |
| Customer auth + staff separation | F01 | Guard/auth tests |
| RBAC + custom roles + protected Super Admin | F02 | Permission/privilege tests |
| Category hierarchy, products, variants, images | F03–F04 | CRUD + public API tests |
| Search/filter/sort/pagination | F04 | Query combination tests |
| Stock correctness + oversell protection | F05, F08 | Concurrency tests |
| Guest cart + merge | F06 | Guest-to-user tests |
| Coupons + limits | F07 | Eligibility and race tests |
| Stripe Test Mode + lifecycle + emails | F08 | Webhook/retry/order tests |
| Admin dashboard/customer management | F09 | Report calculation tests |
| Figma homepage dynamic data | F10 | CMS endpoint contract tests |
| Product reviews/ratings | F11 | Review/moderation tests |
| Docker, 2FA, tests, live demo option | F00, F01, F12 | Setup/security/demo evidence |
| Security, API docs, architecture notes | All, F12 | OpenAPI + CI + audit |

---

## 23. Definition of done per feature

A feature is **DONE** only when all boxes are checked:

- [ ] Requirements and edge cases implemented; assumptions/decisions recorded.
- [ ] Migration, FK/index/unique constraints and rollback verified.
- [ ] Model relationships, casts, scopes and factory completed.
- [ ] Service handles business logic; controller remains thin.
- [ ] Form Request validation and policies/permissions enforced.
- [ ] API Resource and global response contract used.
- [ ] Versioned routes documented in OpenAPI with errors/examples.
- [ ] Positive, negative, ownership, status and concurrency tests as relevant.
- [ ] Seeder/demo fixtures updated; fresh migration works.
- [ ] Audit/status/bulk/reorder behavior implemented **where appropriate**.
- [ ] No sensitive fields exposed; no N+1 or unbounded lists.
- [ ] Existing test suite green; CI/static analysis/formatter pass.
- [ ] README/module handoff note and meaningful commit completed.

---

## 24. Agent operating instructions and anti-ambiguity rules

1. Work on **one feature ID at a time**. State planned files/tables/routes/tests before coding.
2. Respect phase dependencies and gates; no checkout before inventory concurrency tests.
3. Do not create `app/Domain`, repositories, generalized base CRUD controllers or extra layers without explicit approval.
4. Do not edit migrations already applied in shared environments; create new migration.
5. Do not implement unapproved money/tax/shipping/fulfillment assumptions as hidden behavior.
6. Prefer Laravel conventions, native validation, policies, Eloquent and explicit services over custom frameworks.
7. Do not claim a feature complete because endpoints return 200; require tests, docs and security cases.
8. Keep external payment calls outside DB transactions; webhook is source of truth for settlement.
9. Every change to order/payment/inventory status must pass a dedicated service/state transition.
10. Any change to API shape, permission naming or database keys requires OpenAPI/tests/ERD updates in the same feature.
11. If source brief conflicts with enhancement, preserve the brief's required behavior and document the safer compatible implementation.
12. After each feature, output a handoff report: `feature_id`, implemented files, migrations, endpoints, tests run/results, decisions, known limitations, next feature.
13. Never include live credentials, tokens, customer PII, or full Stripe secrets in logs, fixtures or documentation.
14. No frontend work in backend phases; provide stable API contracts for later integration.
15. For user-requested “bonus stuffs,” include the brief's bonuses and Figma-relevant enhancements explicitly listed here; do not interpret this as permission for unlimited unrelated features.

---

## 25. Phase dependency and recommended execution sequence

```text
F00 Foundation / API Standards
  ↓
F01 Customer + Staff Authentication (incl. TOTP)
  ↓
F02 RBAC + Audit
  ↓
F03 Categories + Brands + Attributes
  ↓
F04 Products + Variants + Media + Discovery
  ↓
F05 Inventory + Reservations + Concurrency
  ↓
F06 Addresses + Guest/Customer Cart
  ↓
F07 Coupons + Promotion Validation
  ↓
F08 Checkout + Stripe + Orders + Refunds + Emails  [CRITICAL GATE]
  ↓
F09 Customers + Dashboard + Reporting
  ↓
F10 Homepage CMS + Navigation + Newsletter
  ↓
F11 Reviews + Ratings
  ↓
F12 Security + API Contract + Docker/CI + Demo
```

**Note:** CI, security, documentation and tests begin in F00 and continue in every phase; F12 is final verification, not a delayed start.

---

## 26. Final deliverables

1. Laravel backend repository with MVC + Services, versioned REST APIs and meaningful commits.
2. Complete migrations, model relationships, factories and seeders (Super Admin, five roles, 20+ products/images, categories, coupons, sample orders, CMS data).
3. Tested Sanctum customer/staff authentication, RBAC, admin TOTP.
4. Tested catalog, inventory, cart, coupon, Stripe checkout, order, refund and reporting APIs.
5. Complete OpenAPI 3.x specification, interactive API docs, Postman collection, response/error code catalog.
6. ERD/schema data dictionary, permissions matrix, state transition diagram, Stripe/idempotency design notes.
7. Automated unit/feature/integration/concurrency tests and CI.
8. Docker Compose, `.env.example`, README, deployment/runbook, Mailpit and Stripe test instructions.
9. Frontend integration handoff contract and sample request/response fixtures.
10. Module-by-module completion notes with evidence of passing tests.

### Final sign-off checklist

- [x] Owner confirmed assumptions A-01–A-14 and documented the login and RBAC decisions.
- [ ] Every phase gate passes on a clean environment.
- [ ] Every mandatory requirement from original brief is traceable to tested feature(s).
- [ ] Bonus features included here are working, not stubs.
- [ ] Backend can be used by Next.js without changing core business rules.
- [ ] Final architecture is **MVC + Service Layer**, **no Domain folder**.

**End of Backend PRD v1.0.**
