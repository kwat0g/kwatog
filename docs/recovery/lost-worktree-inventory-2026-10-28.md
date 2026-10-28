# Pre-deletion worktree inventory

Recovered from Codex session logs. Snapshot taken 2026-10-28 around 06:20 UTC.

The “87” is the number of `git status --porcelain` entries across six dirty worktrees. It is not 87 commits or 87 separate projects. Some entries were untracked screenshot directories, each of which can contain multiple files.

## 1. HR separation clearance badge — 6 entries

Worktree: `/home/ec2-user/kwatog-finalpay-recovery`

- `api/app/Modules/Dashboard/Services/BadgeService.php`
- `api/app/Modules/HR/Services/SeparationService.php`
- `api/app/Providers/AppServiceProvider.php`
- `spa/src/api/separations.ts`
- `spa/src/components/layout/Sidebar.tsx`
- `spa/src/pages/hr/separations/index.tsx`

Branch label at snapshot: `notifications/hr-separation-clearance-badge`. Likely work area: badge and notification support for HR separation clearance/final pay. The files were modified, not committed.

## 2. Gallery / StatCard visual check — 6 entries

Worktree: `/home/ec2-user/kwatog-layout-gallery-check`

- `spa/e2e/__screenshots__/gallery.spec.ts/panels-1280.png`
- `spa/e2e/__screenshots__/gallery.spec.ts/panels-390.png`
- `spa/e2e/__screenshots__/gallery.spec.ts/records-1280.png`
- `spa/e2e/__screenshots__/gallery.spec.ts/records-390.png`
- `spa/src/components/ui/StatCard.tsx`
- `spa/src/components/ui/StatCard.test.tsx` (untracked)

## 3. Layout audit — 23 entries

Worktree: `/home/ec2-user/kwatog-layout-production`

Modified files recorded:

- `docs/layout/FINDINGS.md`
- `docs/layout/PROGRESS.md`
- `docs/layout/page-issue-matrix.csv`
- `docs/layout/route-inventory.csv`
- `docs/layout/surface-inventory.csv`
- `spa/scripts/layout-audit.mjs`

Untracked screenshot directories recorded:

- `docs/layout/screenshots/after/inventory-core/dark-probe/`
- `docs/layout/screenshots/after/inventory-core/light-probe/`
- `docs/layout/screenshots/after/inventory-core/light-probe2/`
- `docs/layout/screenshots/after/inventory-core/light-probe3/`
- `docs/layout/screenshots/after/inventory-core/light-probe4/`
- `docs/layout/screenshots/after/inventory-core/light-review/`
- `docs/layout/screenshots/after/inventory-core/light-review2/`
- `docs/layout/screenshots/after/inventory-core/light-review3/`
- `docs/layout/screenshots/after/inventory-core/tmp-compact/`
- `docs/layout/screenshots/after/inventory-dashboard/`
- `docs/layout/screenshots/after/production-routings/contain-layout-test/`
- `docs/layout/screenshots/after/production-routings/diagnostic/`
- `docs/layout/screenshots/after/production-routings/repeat-1/`
- `docs/layout/screenshots/after/production-routings/repeat-2/`

Three additional status entries were omitted from the captured output because the command printed only its first 20 rows. An earlier session in the same worktree recorded these two screenshot directories, which may be among the omitted entries but are not confirmed:

- `docs/layout/screenshots/after/production-routings/repeat-3/`
- `docs/layout/screenshots/before/production-routings/`

The third omitted path remains unknown.

## 4. Final-pay / notifications conflict recovery — 3 entries

Worktree: `/home/ec2-user/kwatog-operational-channels-recovery`

- `api/app/Modules/HR/Services/FinalPayService.php` (staged)
- `docs/notifications/FINDINGS.md` (unresolved conflict)
- `docs/notifications/PROGRESS.md` (unresolved conflict)

## 5. SPA Zod 4 / resolver compatibility and type fixes — 47 entries

Worktree: `/tmp/ogami-spa-ci-check`, detached at `d0117fdea`.

- `spa/package-lock.json`
- `spa/package.json`
- `spa/src/components/crm/ProductPhoto.tsx`
- `spa/src/components/hr/CreateAccountModal.tsx`
- `spa/src/lib/formErrors.ts`
- `spa/src/main.tsx`
- `spa/src/pages/accounting/bills/create.tsx`
- `spa/src/pages/accounting/bills/detail.tsx`
- `spa/src/pages/accounting/credit-notes/detail.tsx`
- `spa/src/pages/accounting/credit-notes/index.tsx`
- `spa/src/pages/accounting/customers/form.tsx`
- `spa/src/pages/accounting/invoices/create.tsx`
- `spa/src/pages/accounting/vendors/form.tsx`
- `spa/src/pages/admin/roles/create.tsx`
- `spa/src/pages/admin/users/create.tsx`
- `spa/src/pages/assets/create.tsx`
- `spa/src/pages/assets/edit.tsx`
- `spa/src/pages/attendance/overtime/create.tsx`
- `spa/src/pages/attendance/shifts/index.tsx`
- `spa/src/pages/crm/complaints/create.tsx`
- `spa/src/pages/crm/customers/create.tsx`
- `spa/src/pages/crm/customers/edit.tsx`
- `spa/src/pages/crm/products/detail.test.tsx`
- `spa/src/pages/crm/products/detail.tsx`
- `spa/src/pages/hr/recruitment/postings/create.tsx`
- `spa/src/pages/hr/recruitment/postings/edit.tsx`
- `spa/src/pages/hr/training/form.tsx`
- `spa/src/pages/inventory/items/form.tsx`
- `spa/src/pages/inventory/warehouse/index.tsx`
- `spa/src/pages/landing/sections/ContactSection.tsx`
- `spa/src/pages/leaves/create.tsx`
- `spa/src/pages/leaves/types.tsx`
- `spa/src/pages/loans/create.tsx`
- `spa/src/pages/maintenance/schedules/create.tsx`
- `spa/src/pages/maintenance/schedules/edit.tsx`
- `spa/src/pages/purchasing/approved-suppliers/index.tsx`
- `spa/src/pages/purchasing/purchase-orders/components/ShortClosePoModal.tsx`
- `spa/src/pages/purchasing/purchase-orders/create.tsx`
- `spa/src/pages/quality/calibration/form.tsx`
- `spa/src/pages/quality/inspection-specs/editor.tsx`
- `spa/src/pages/quality/ncrs/create.tsx`
- `spa/src/pages/return-management/create.tsx`
- `spa/src/pages/self-service/leave.tsx`
- `spa/src/pages/self-service/loans.tsx`
- `spa/src/pages/supply-chain/deliveries/create.tsx`
- `spa/src/stores/tablePrefsStore.ts`
- `spa/src/lib/zodConfig.ts` (untracked)

Session history shows this work involved `@hookform/resolvers` 5.x, Zod 4 input/output typing, form error handling, and SPA type/lint/test checks. It also records the Zod type migration script and test diagnostics.

## 6. Purchase-request authorization — 2 entries

Worktree: `/var/tmp/kwatog-notification-338`

- `api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php`
- `api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php`

Branch label at snapshot: `notifications/fix-pr-approval-badge-scope`.

## Other deleted untracked files in the main checkout

These seven were removed separately and are not part of the 87 status entries:

- `dasdsdaa`
- `dashboard.md`
- `docker-compose.yml.dev.bak`
- `docker/nginx/default.conf.dev.bak`
- `docs/dashboards/md`
- `pdf.md`
- `session-ses_ed4b.md`

## Artifacts that remain

- `/home/ec2-user/ogami-zod4-type-fixes.patch` — an 8-file patch from an earlier Zod compatibility checkpoint; it is not a full replacement for the 47-entry worktree.
- `/home/ec2-user/ogami-layout-phase2.patch` — an earlier layout patch; not confirmed to match the 23-entry layout worktree.
- `/home/ec2-user/quality-inspection-combined.patch` — an earlier quality/notification patch; not confirmed to match the 3-entry conflict worktree.
- `/home/ec2-user/kwatog-layout-gallery-recovery.bundle` — preserved five-commit history from the detached gallery checkout. It does not contain its uncommitted edits.

This inventory identifies recorded paths and topics. It cannot restore uncommitted file contents.

## Cross-check against current `main`

The 87 status rows do not equal 87 lost changes. Later commits already cover much of this work:

- All 47 SPA/Zod paths occur in merged PR #368 (`2b1493aca`, `fix/spa-typecheck-baseline`). The Zod 4 / resolver compatibility work is on `main`; do not redo it.
- All six gallery paths have current versions on `main`: the four screenshot files were updated in `063b22ff7`, and `StatCard.tsx` plus `StatCard.test.tsx` are tracked on `main`. Exact byte-for-byte comparison to the deleted worktree is impossible.
- `PurchaseRequestAccessPolicy.php` has later `main` updates in commits `6c25d82c5`, `1bce2e69c`, and `3e46d9cb6`. `PurchaseRequestHardeningTest.php` has no later commit after its worktree base, so its local test edit may be missing.

Best current redo list:

- Five of six HR separation files have no later `main` edits after base `305b1b1ff`; likely missing work. `Sidebar.tsx` was changed later on `main`, so its exact local delta is ambiguous.
- The 23 layout-audit status entries have no later `main` updates after base `305b1b1ff`; likely missing work. Three path names were omitted from the log's 20-row output.
- `FinalPayService.php` has no later `main` update after base `71c8cc616`; its staged edit is likely missing.
- `PurchaseRequestHardeningTest.php` has no later `main` update after base `5569d8977`; its local test edit is likely missing.
- The two notification docs conflict files have later `main` edits; their exact local conflict edits cannot be separated from changes already merged.

This leaves about 30 likely unique status entries to redo, plus three ambiguous paths (`Sidebar.tsx` and the two notification docs). This is a path-based estimate, not a verified line-diff count.

## Recovery status

This section supersedes the earlier redo estimate above.

- **HR separation badge (6 entries):** rebuilt on current `main`. Badge and filtered list share signer/department scope; clearance changes invalidate the badge cache. API and sidebar regression tests were authored but not run.
- **Gallery / StatCard (6 entries):** the accessible delta labels, focused component test, and current gallery baselines are already on `main`; no duplicate changes needed.
- **Layout audit (23 entries):** rebuilt local fixtures and canonical Light/Dark evidence for `/inventory/dashboard` at seven widths. Updated route/surface inventories and recorded F-018. Repeated probe/review screenshots are not reproduced; three omitted snapshot paths remain unknown. This audit records an open UI finding; it does not change the page implementation.
- **Final Pay / notification conflict recovery (3 entries):** the staged `FinalPayService.php` object contains only an import already present on `main`. Current notification documents were preserved; the original two-file conflict resolution cannot be recovered exactly.
- **SPA Zod 4 / resolver fixes (47 entries):** already included by merged PR #368; no duplicate changes needed.
- **Purchase-request authorization (2 entries):** current `main` contains the later access-policy fixes. The local hardening-test diff was not preserved, so no speculative test deletion or rewrite was added; current tests remain on `main`.

The 87 count is status-path entries across six worktrees, not 87 distinct code changes. The seven separately deleted untracked files listed above were outside that count and were not recovered; no copies were found in the workspace or temporary directories. Their original contents remain unknown.
