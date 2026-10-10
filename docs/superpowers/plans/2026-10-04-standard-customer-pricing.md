# Standard customer pricing implementation plan

Execute inline in the authorized shared workspace. Preserve existing changes; no automatic commits.

1. Write API regression tests for standard/base ordering, private assignments, quote overrides/date/volume behavior, configured price floors and unchanged order price preservation. Run them to demonstrate missing behavior.
2. Add nullable product pricing fields, portal visibility, customer assignment pivot and optional SO line price-source snapshots. Backfill only assignments from existing agreement relationships; leave base/floor prices unset.
3. Extend product requests/service/resource with validated price fields and explicit customer assignments. Preserve archived/reference protections.
4. Extend PriceAgreementService with shared quoting and floor checks, using authoritative reference locks and one consistent lock order. Keep existing agreement resolution helpers compatible.
5. Separate portal eligibility from agreement existence. Add authenticated customer quote endpoint with original-order ownership checks, exact totals and signature. Share quote logic with SO creation/update and preserve unchanged historical commercial lines.
6. Add product pricing/visibility controls, explain agreement overrides, and connect the compact order form to authoritative quotes. Block stale quote saves and show source, discounts and exact totals.
7. Run focused API/UI checks and production build, apply only additive migrations to audit/live databases, and execute isolated role-based headless browser checks. Confirm existing live SO remains unchanged.
8. Record validation and explain each completed behavior and the required real-price setup.
