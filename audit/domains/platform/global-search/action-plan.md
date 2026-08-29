# M009 — Global Search action plan

Status: 🔁 Needs Re-audit
Last updated: 2026-08-30 (re-audit)
Overall recommendation: separate-recommended for what remains

## Closed

| Finding | Closed by | Verified |
|---|---|---|
| M009-F01 … F08 | `1a5d2122` | 2026-08-26 |
| M009-F11 — employee middle names | `e0530b33` | re-verified green 2026-08-30 |
| M009-F12 — normalize before validation | `e72c3315` | re-verified green 2026-08-30 |
| M009-F15 — palette focus trap | `5cd6e7e5` + `6e932ad9`/`44f6e1b3` | Chromium + Firefox at `44f6e1b3` |
| **M009-F09 — per-module feature gate** | **this session** | **red/green, 29 tests / 142 assertions** |

## Ordered actions

| Order | Finding | Size | Session tag | Acceptance evidence |
|---:|---|---|---|---|
| 1 | **M009-F17** — PO search window is broader than the PO list for `production_manager`. Repair in `PurchaseOrderService::list()` by adopting `DepartmentScope`, removing the `$roleSlug === 'department_head'` branch. | **medium** | **separate-recommended — cross-module, owned by `procurement/purchase-orders`** | For every role, a PO returned by `/search` is also returned by `/purchasing/purchase-orders`, and vice versa. The measured `production_manager` row (SEARCH=YES / LIST=no) becomes YES/YES or no/no. No role-slug branch remains. |
| 2 | **M009-F14** — decide and enforce the archived-relation contract (label and/or predicate). | **medium** | **separate-recommended** | A live parent with a soft-deleted counterparty has one explicit, documented outcome; searching the archived name either reaches the transaction by design or reaches nothing, and a fixture asserts which. |
| 3 | **M009-F18** — give the palette a real combobox/listbox contract: `aria-activedescendant`, `role="listbox"`/`option`, `aria-live` for counts and failures. | **small/medium** | **separate-recommended** | A screen reader announces the highlighted row as ↑↓ moves it, and announces "N results" / "No results" / the failure title. Chromium run required — this touches the same handler `44f6e1b3` verified, so the focus-trap spec must be re-run green in the same session. |
| 4 | **M009-F10** — invalidate or revalidate recents when the same user's effective permissions/features change. | **medium** | **separate-recommended** | A permission override or module toggle removes or revalidates affected recents; no stale label/sublabel renders afterwards; localStorage regressions pass. |
| 5 | **M009-F13** — make the query budget executable and choose a production search strategy. | **large** | **separate-recommended** | A runtime query/latency ceiling is enforced rather than commented; the docblock's figure matches a measured end-to-end count (currently 30 vs a documented 22); a production-sized plan justifies prefix / trigram / full-text; cross-module migration ownership is recorded. |
| 6 | **M009-F19** — resolve the `search.global` grant shape: the four operational roles that 403, and `maintenance_tech`'s empty search. | **small** | **separate-recommended — owned by `platform/rbac`; needs a product answer first** | Either the grants change, or the decision is recorded as intentional and the palette stops promising record search to a caller holding no entity gate. |
| 7 | **M009-F16** — resolve SQLite wildcard semantics: an explicit driver-safe `ESCAPE` strategy with coverage, or narrow the helper's advertised contract to PostgreSQL. | **small** | **separate-recommended** | The helper's docblock and its behaviour agree; literal `%`/`_` regression tests pass on every driver the docblock still claims. |
| 8 | **M009-F20** — `docker rm ogami-meili`; correct the `modules.search` label from "Full-text search across all modules" to what it is. | **small** | **same-session-ok** (host state + one seeder string; not a `global-search` source file) | `docker compose` runs without the orphan warning; the Settings label no longer advertises full-text search. |

## Implementation sequence

1. F17 first — it is the only remaining item where search returns a record the caller's own
   list page hides, and it is the one item whose fix lives in another module. Do not "fix"
   it inside `GlobalSearchService`: no permission distinguishes `department_head` from
   `production_manager`, so narrowing search there requires the role-slug branch CLAUDE.md
   forbids. Widen the list instead.
2. F14 needs the owning modules' answer before code. Get the decision, then apply the same
   relation semantics to every joined label in one pass — POs, SOs, invoices, bills, work
   orders — rather than one group at a time.
3. F18 and F10 are both SPA and both touch `CommandPalette.tsx`; pair them in one session
   with a Chromium run so the focus trap from `44f6e1b3` is re-proven once, not twice.
4. F13 last of the substantive items: an index choice made before production-sized data is
   a guess, and the migrations span eleven other modules' tables.
5. F19 and F16 are contract/product cleanups; take them whenever the owning card is open.
6. F20 is a two-minute hygiene item that removes a false warning from every developer
   command in the repo — worth doing early precisely because it is cheap.

## Gate decision for this session

The plan was **not** taken wholesale. One item was fixed and the rest deferred, deliberately:

- **Fixed: F09.** Judged **`same-session-ok` despite being an authorization-shaped change**,
  on containment rather than on category. Per the brief, adding a missing gate to a leaking
  surface is a security fix, not a permission redesign. Concretely: it is one static map and
  one `&&` per group inside a single service file, it touches no state machine, no money, no
  migration and no other module, `SettingsService` was already app-wide, and the change is a
  pure narrowing — with every toggle at its seeded default (`true`) behaviour is byte-for-byte
  what it was. It is also the only open P1 whose fix lived entirely inside this module.
- **Deferred: everything else.** F17 is P1 but its correct fix is in another module's service.
  F14 and F19 need a human decision before any code. F18 and F10 need a browser run that
  this session cannot perform (`nginx`/`spa` stopped, shared host). F13 is large and
  cross-module. F20 is host state.

Six of the eight remaining items are `separate-recommended`, so the module is released
`🔁 Needs Re-audit`, not `✅ Verified`.
