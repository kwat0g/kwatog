# Overnight session — 2026-08-27

**Goal:** drive the suite to zero failures except items that genuinely require a
human decision. Commit per module. Never run a full suite while agents are live
(that combination OOM-killed the stack on 2026-08-26). Max 4 concurrent agents.

Branch: `audit/2026-08-26-five-modules`. `main` untouched. Nothing pushed.
Recovery snapshot if anything goes wrong: `git checkout wip-snapshot-2026-08-26`.

---

## READ THIS FIRST — 7 decisions only you can make

None of these are blocked on work. Each was deliberately left undecided because
picking wrong moves money, changes who can see what, or trades away an integrity
guarantee. Evidence for each is in the named module's `fix-log.md`.

1. **Audit immutability vs. user deletability.** `audit_logs.user_id` has an
   `ON DELETE SET NULL` FK, and `audit_logs` carries an `audit_logs_prevent_update`
   trigger forbidding any update. So **a user with audit rows cannot be deleted at
   all, in production** — which contradicts CLAUDE.md listing users as
   soft-deletable. Either the FK becomes `RESTRICT` with an explicit "archive,
   never delete" policy, or the trigger gains a narrow exception for the FK
   cascade. Do not let anyone "fix" this by loosening the trigger to make tests
   pass. → `finance/chart-of-accounts-periods`

2. **Customer portal reactivation.** `inviteSupplier()` refuses invitations to
   inactive accounts, safe because suppliers have a `reactivateSupplier` endpoint.
   Customers have none — nothing anywhere deactivates a `CustomerPortalUser`, so
   re-invitation is the only way to re-enable one. Mirroring the guard would
   permanently strand any inactive customer account. Either keep re-invite as the
   reactivation path, or build customer deactivate/reactivate/revoke endpoints
   first. → `commercial/customer-portal`

3. **Scrapped-line credits (money).** The code credits scrapped return lines. The
   test named for *not* crediting them has always posted `return_to_supplier`,
   never `scrap`. The agent's reading is that the code is right — the customer is
   owed the credit and scrapping is our loss — making the `dispose()` docblock the
   wrong part. Nothing was changed either way. → `supply-chain/returns-rma`

4. **AP `payments` visibility.** `AccountsPayableHardeningTest` asserts the
   supplier bill resource exposes no `payments` key, but `invoiceDetail()`
   eager-loads payments so it is populated live. Fields are
   date/amount/method/reference/status, no journal or GL, and suppliers already
   see `amount_paid`/`balance`. Partly a test artifact, partly a real
   supplier-visibility call. → `supply-chain/supplier-portal`

5. **Routing rollback blocked by retired molds.** `activate()` re-validates a
   stored routing, so rollback to a historical version is refused if its mold has
   since been retired. Correct for IATF traceability, but it means an emergency
   rollback can be blocked by unrelated master-data state.
   → `manufacturing/production-routings`

6. **Complaint cancellation under IATF retention.** `ComplaintController`
   publishes `ComplaintStatus::cases()` verbatim, so the UI offers a selectable
   **Cancelled** filter that can only ever return zero rows, while the escalation
   service treats the status as load-bearing. Is cancelling a complaint permitted
   under IATF 16949 retention, and if so may it happen once an NCR exists?
   → `commercial/customer-complaints-8d`

7. **Raw-punch import reachability.** The service and `PunchSessionizer` are
   complete and tested but unreachable — no route, no UI. The deciding evidence is
   what the FCIE Dasmariñas terminals actually export; if they emit raw punches,
   exposing it is required for real hardware. → `people/attendance-dtr`

Also queued behind you, not a decision but only you can do it:
**`sudo chown -R $USER spa/node_modules spa/test-results`** (or `rm -rf
spa/node_modules/.vite-temp spa/test-results`). Root ownership there blocks BOTH
Vitest and Playwright. Two separate sessions misdiagnosed it as "the compose
image has no Chromium" — Chromium is present on the host; Vite simply cannot
write its config temp file, so the dev server never boots.

---

## The one defect that caused most of today

`2026_08_25_210000_enforce_one_active_holiday_per_date` ran
`DROP INDEX IF EXISTS holidays_date_name_unique`, but `0023_create_holidays_table`
backs that name with a UNIQUE **constraint**, which PostgreSQL refuses to drop
that way (`SQLSTATE 2BP01`). That single line failed `migrate:fresh` **repo-wide**.

Consequence: ~50 audit sessions wrote complete `fix-log.md` files, committed their
code, and **never executed a single line of it**. Every module resumed on
2026-08-26 was in that state. Their "fixes" were source-only, and running them is
what exposed the real bugs:

- a credit note paying for 10 units when 8 came back (money)
- every purchase request stalled at approval step 2, forever
- a restore path that had never once worked
- a filename regex that made **every** delivery-proof download a 500
- 8D SLA escalation that could never fire, behind a cron reporting SUCCESS every
  15 minutes for a day
- a customer portal account that could be silently taken over by another customer

Fixed in `c7b2c483`. The lesson is recorded in CLAUDE.md.

---

## Process changes made (so this cannot recur)

- `audit/scripts/02-module-session.md` gained **Step 7b: commit before releasing**,
  incremental `fix-log.md` writing, orphan-lock semantics, per-session test
  databases, and the fact that `regenerate-registry.sh` is not parallel-safe. Its
  `/audit/scripts/...` paths were also wrong — no such filesystem root — so every
  command in it failed instantly as written.
- CLAUDE.md: migration max corrected (was 0474, actually 0478) with instructions
  to verify rather than trust it; the `0NNN_` vs `2026_*` ordering trap documented
  (numbered files always run first, and guards silently no-op); and a warning that
  `catch (Throwable) → Log::*` around both work *and* its failure-recorder makes a
  dead subsystem look healthy.
- `audit/domains/*/*/.lock/` is now gitignored — 88 lock files had been committed
  by accident, so a fresh checkout would have resurrected 44 stale locks.

---

## Status log (appended as the night proceeds)
