# Catalog taxonomy

Phase 3 provides categories, brands, and attributes with scoped attribute values. Public records use ULIDs internally for API identifiers and expose slugs for navigation. New admin records start inactive. Use the dedicated status endpoint to publish them.

- Categories can have multiple levels. Moving a category locks the tree before checking ancestors. An inactive or soft-deleted parent hides its descendants from the public tree.
- Brands carry an optional HTTP or HTTPS logo URL. The public brand list is ordered and includes active brands only.
- Attribute values belong to one attribute and their slugs are unique per attribute. Public attributes show only active options.
- Admin lists support search, status filters, sorting, pagination, and deleted records. Reorder requests replace the complete order for one group. Bulk changes return per-item failures and respect update or delete permissions.
- The catalog seeders install sample records only in local and testing environments when called through the default seeder.

The [Postman collection](../api/ecommerce.postman_collection.json) includes the storefront and admin catalog operations. The [OpenAPI contract](../api/openapi.yaml) carries the same paths.
