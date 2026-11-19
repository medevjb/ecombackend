# Product reviews

Verified purchase is required by default. A captured order containing the product qualifies while it remains uncancelled and has a succeeded or partially refunded payment. Deployments can allow unverified reviews with `REVIEWS_REQUIRE_VERIFIED_PURCHASE=false`. The API marks their purchase status.

Approval, edits, and deletions adjust the product rating aggregate within the review transaction. An edit that changes the content returns it to pending. A customer can resubmit a deleted review using the original review identity. Staff moderation requires a reason and is recorded in the audit log. Public review text is plain text and omits customer identifiers.
