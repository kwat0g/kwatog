# Fixed Assets — Register, Depreciation, QR & Disposal

**Demo logins:** `finance@ogami.test` (Finance Officer, manages assets) ·
`vp@ogami.test` (Vice President, approves disposals) · password: `password`

Log in at `/sign-in`, click **Sign in**.

## 1. The big picture (30 seconds)

Every big-ticket item (press, mold base, van) is an **asset**: bought once,
**depreciated** monthly, tracked by **QR**, and **disposed** only with
Finance + VP approval (money leaves the books — maker-checker).

## 2. The fixed-assets register

1. Log in as `finance@ogami.test`. Open `/assets` (**Fixed Assets** in sidebar).
2. Browse code, category, cost, book value, status per asset.
3. Click a row → `/assets/:id` detail: cost, depreciation history
   (**Depreciation history** panel), status dates, and the **QR code** panel.
4. Click **Download QR** in the QR panel, print it, stick it on the machine —
   scanning it opens this same detail page.

## 3. Add or edit an asset

1. On `/assets`, click **New asset** → `/assets/create` (page **New asset**):
   code, category, cost, **Depreciation method**, dates. Submit **Create asset**.
2. Edit at `/assets/:id/edit`.

## 4. Monthly depreciation run

1. On `/assets`, click **Run depreciation** (header button).
2. The **Run monthly depreciation** modal explains: one consolidated journal
   entry, DR Depreciation Expense / CR Accumulated Depreciation, idempotent
   (re-running the same month is safe).
3. Click **Run depreciation** (shows **Running…**). For catch-up months use
   **Run backfill** (shows **Backfilling…**).
4. The entry posts to the GL — mention it, then show the updated
   **Depreciation history** on any asset.

## 5. Dispose an asset (Finance proposes, VP disposes)

1. As `finance@ogami.test`, open an asset (`/assets/:id`) and click **Dispose**.
2. The **Dispose asset** modal: enter **Disposal proceeds** (₱), **Disposal
   date**, **Reason**, then click **Submit for approval** (shows **Submitting…**).
3. Log out. Log in as `vp@ogami.test`.
4. Approve in the **Approve disposal?** dialog. The chain is Finance
   (Reviewed by) → VP (Approved by) per the seeded `asset_disposal` workflow.
5. Once approved, the asset shows its disposed date and the JE posts.

## 6. Hidden note — asset transfers (do NOT demo live)

- The asset transfer pages were **removed (scope cut)** — the route does not
  exist. If a panelist asks "how do I move an asset to another department?":
  record it via the asset **Edit** page — there is no transfer-approval flow
  in this build.

## 7. What each login can and cannot do

- `finance@ogami.test` holds the entire assets module.
- `vp@ogami.test` holds only `assets.view` + `assets.dispose.approve` — it
  signs what Finance proposes, nothing else.
- `production@ogami.test`, `maintenance@ogami.test`, `ppc@ogami.test` see the
  register (`assets.view`) but cannot change anything.

## 8. If something looks wrong

- **Run depreciation** missing? Log in as Finance.
- **Dispose** missing? Needs dispose permission — be Finance, not VP.
- Approval stuck? Both steps must be different people (maker-checker):
  Finance submits, VP approves. Self-approval is refused.
