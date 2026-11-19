# Admin customers and reporting

Customer reads use the customer identity scope. Deactivation revokes customer tokens and preserves order and payment history. Staff with customer management permission can reactivate an account.

Reports use UTC and inclusive calendar-date filters. Daily net revenue equals captured payments recorded on that date minus refunds completed on that date. A day with more refunds than captures can have negative net revenue. The dashboard caches its 30-day sales series for 60 seconds.

Product and category breakouts show pre-discount merchandise amounts from order lines at the date of payment. Category totals follow the current product taxonomy. A product in multiple categories contributes to each category.
