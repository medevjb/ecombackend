# API foundation decisions

The owner approved the PRD defaults A-01 through A-14, public ULIDs, the Stripe PHP SDK for Test Mode, and the following choices.

- Expose one credential endpoint at `POST /api/v1/auth/login`. A normalized email identifies exactly one account across customer and staff providers. Login returns roles and a dashboard audience for frontend navigation. Staff APIs enforce the staff guard and Tyro privileges. During the transition, preserve existing local staff accounts and their role assignments.
- Use Tyro for staff role and privilege management. Customer tokens cannot authorize staff routes.
- Use bigint internal keys and ULIDs for public resource identifiers on new commerce resources. Scope resource access through policy and ownership checks.
- Use credential-free CORS with explicit storefront and admin origins. Clients send Sanctum bearer tokens in the authorization header.

## Admin resource conventions

- List requests allowlist filter names and sort columns. Sort ties by the primary key in the requested direction. Paginate at 20 records by default and validate a maximum of 100. Return paging details under `meta.pagination`.
- Accept `sort_order` only on resources with an explicit display order. Validate the ordered scope before writing in a transaction.
- Derive `created_by` and `updated_by` from the authenticated staff identity. Treat them as read-only on input.
- Use a resource-specific status enum and policy. Add soft delete and restore only for eligible resources. Authorize each item in a bounded bulk request and return succeeded IDs and per-item failure details.
- Audit entries are read-only. The list exposes the actor ID, action, target and timestamp. Raw change payloads and metadata stay out of the response.
