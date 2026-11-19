# Storefront CMS and newsletter

The staff CMS stores ordered, typed content records behind separate management endpoints for banners, sections, navigation, and testimonials. Site settings have a single record. The public endpoints include enabled content within its publication window.

Dynamic New Arrivals and Top Selling sections use the storefront catalog. Curated sections preserve staff-selected product order while omitting unavailable products. Category and brand sections use the current taxonomy. Tile sections carry editorial text and links. When an image is missing or fails to load, the client can use the optional `image_fallback_url` from site settings. Render content fields as plain text.

Newsletter consent creates an active record per normalized email. The queued transactional confirmation contains an unsubscribe code for the storefront opt-out form. Suppressed contacts need that code and fresh consent to reactivate. The API stores only the code hash and rotates the code on reactivation. Mailpit can receive the confirmation when the queue worker and local SMTP are running.
