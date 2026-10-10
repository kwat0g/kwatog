# Work order reservation grouping — October 6, 2026

Material reservations now display one expandable row per work order. The summary shows the work order, distinct material count, reservation status counts, and latest reservation time. Expanding a row shows each matching reservation's material, location, exact quantity and unit, status, and timestamp.

The existing reservation endpoint accepts `group_by=work_order`. Pagination counts work orders and retrieves all matching lines for each selected group, so a work order is not split across pages. Existing callers without grouping retain the flat response. Search and status filters apply to both the summaries and their detail lines. Unlinked reservations remain visible. Stock quantities and reservation state are not modified.

The interface reuses the shared DataTable expansion control, including keyboard operation and an explicit expanded state. Work order links still require production viewing permissions. Narrow screens scroll the table horizontally without wrapping identifiers into unreadable fragments.

Reservation summary rows also toggle their details when clicked anywhere outside a link or control. Focused rows support Enter and Space. This behavior is enabled only for this table; other tables retain their existing click behavior. Three reservation interface tests and a live warehouse Playwright check verified whole-row clicks, keyboard toggling, and chevron operation.

Verification:

- Seven backend tests passed with 63 assertions, including whole-work-order pagination, mixed reservation states, material search, unlinked records, and the existing handover safety checks.
- Three frontend tests passed, covering expansion and collapse, exact quantities and locations, permission-controlled links, grouped requests, and debounced filtering.
- Live headless Playwright authenticated as the warehouse account. Twelve reserved lines appeared as four work order summaries. `WO-202610-0014` expanded to all three expected quantities: `RM-001` 1.890 kg, `RM-011` 0.204 kg, and `PKG-001` 100 pieces. Search, keyboard activation, desktop and mobile layouts, and collapse passed without browser errors or document overflow.
- TypeScript, targeted ESLint, PHP formatting, the design detector, and the production build passed. The build retains the existing warning about the NotFound page being imported both statically and dynamically.
