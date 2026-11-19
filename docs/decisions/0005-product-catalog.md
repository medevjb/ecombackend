# Products, variants, media, and discovery

Product prices are USD minor units. A sale amount must be strictly below the regular amount. Storefront price filters and ordering use the product's displayed sale or regular amount. Variant price overrides are exposed on the option detail.

Creating a product also creates its default SKU. Product writes begin in draft and publishing requires a category and an eligible variant. Additional variants have unique SKUs and option combinations, with at most one option per attribute. Soft deletion preserves product and variant identifiers for order history. Restoring a product returns it to draft.

The storefront hides products without a visible category and an eligible variant. Availability uses on-hand minus reserved stock. The catalog schema includes inventory rows and a metrics projection so discovery can query real data. Phase 5 owns inventory mutations. Paid-order settlement and review moderation will maintain the sales and ratings projections in their phases. A missing projection means zero eligible sales and no approved reviews.

Images use the configured `PRODUCT_MEDIA_DISK`. For the local public disk, run `php artisan storage:link --no-interaction`. Upload and replacement accept JPEG, PNG, or WebP files with validated dimensions. Replacing or removing an image keeps its prior file available for historical snapshots. No production object store was provisioned in this phase.

Operations are in the [Postman collection](../api/ecommerce.postman_collection.json) and [OpenAPI contract](../api/openapi.yaml).
