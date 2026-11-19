# Staff role and permission management

Tyro stores staff roles and privileges. The API uses the permission names in `config/staff-access.php`. Staff may receive multiple roles, and permissions are evaluated from current grants on each request. The staff profile exposes those grants for client navigation.

Only Super Admin can change roles and staff assignments. Delegated Admin can read roles, staff, and audit logs. Order Manager can read audit logs. The seeded Super Admin role is immutable. Staff assignment changes and suspensions lock the Super Admin role before checking that at least one active Super Admin remains.

A role slug is its stable API identifier. Staff routes identify accounts by public ULID. The audit listing exposes event and target metadata and keeps raw before/after payloads internal. Role-permission replacements, staff assignments, and staff activation changes include a request ID in the audit record.

The local Postman files cover Phase 0–2. The CI workflow runs the full test suite, static analysis, migration checks, and OpenAPI validation. Seed grants using `StaffAccessSeeder` after moving the code to a new database.
