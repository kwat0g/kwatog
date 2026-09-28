# QC Lot Capture — Design

**Date:** 2026-09-29
**Status:** Approved (design), pending spec review before planning
**Modules:** Quality (primary), Inventory (section 5), SPA (`pages/quality`, `pages/inventory/grn`)

## Problem

Capture cost scales with lot size instead of with inspection effort. A 2,000-piece
receipt means 125 piece columns × N dimensions of numeric entry for outgoing QC, the
incoming checklist silently renders nothing when its setting is missing, and the GRN
receiving form keeps six optional fields permanently expanded under every PO line.

The fix is not "add a checkbox to the grid". It is to make the stored data match how the
inspection is actually performed:

- The **AQL sample** (n=125 at 2,000 pieces, code K) is inspected **visually**; the
  evidence that belongs in the record is the **count of defective pieces**, not 125
  caliper readings per dimension.
- **Dimensional measurements** are taken on a **few pieces** per lot, and process
  capability accumulates by pooling those across many lots — `SpcService` already
  aggregates `measured_value` over every completed inspection of a spec revision, so five
  pieces per lot is statistically adequate and 125 is not required.

Two supporting facts make this safe:

1. `InspectionService::complete()` blocks a `per_unit` inspection whose declared sample
   exceeds the pieces measured — *"inspection declares a sample of 125 unit(s) but only 5
   were measured"*. That guard is `per_unit`-only, so switching outgoing and in-process to
   `lot_checklist` is exactly what unlocks a smaller measured set.
2. `lot_checklist` mode is stage-agnostic already: it is a scaffold shape (checklist rows
   + N piece rows), stored per inspection, and the frontend branches on it. Nothing about
   it is incoming-specific except the name.

## Goals

- Outgoing and in-process QC on a large lot is recordable with a checklist and a defect
  count, plus a small measured set for tolerance-bearing dimensions.
- A receipt with many lines is reviewable on one screen, still one inspection per line.
- An incoming inspection always has something to tick, or the GRN says why it does not.
- Certificates of Conformance remain issuable for lots that pass with defects within Ac.

## Non-goals

- Changing AQL policy, acceptance numbers, or the sampling plan (`quality.aql.sample_plan`
  is untouched; 125 stays the declared sample at 2,000 pieces).
- Changing in-process acceptance policy (Ac 0 / Re 1 stays).
- Retro-changing existing inspections. `inspection_mode` is stored per row; legacy
  `per_unit` inspections keep their grid and their rules.
- Attributing 100% of a lot as inspected. The AQL sample remains a sample.
- Any change to GRN acceptance/rejection or MRB disposition semantics.

## Section 1 — One capture model for every stage

`inspection_mode = lot_checklist` becomes the mode for **incoming, in-process and
outgoing**.

Scaffolding rule, currently duplicated inside `createIncomingFromPlan()`, is extracted to
one private method `InspectionService::scaffoldLotChecklist(Inspection $inspection, iterable
$parameters, int $measuredPieces)` and called by all three creation paths:

| spec parameter | rows produced |
|---|---|
| `tolerance_min` **and** `tolerance_max` both set | piece rows, `sample_index` = 1..`measuredPieces` |
| otherwise | one checklist row, `sample_index` = 1, `tolerance_min`/`tolerance_max` null |

The split is not a convention — the API enforces it. `InspectionService::recordLotResult()`
rejects a checklist row that carries tolerance bounds and a piece row that lacks them, and
`CoCService` and `complete()` both count failing rows by `tolerance_min`/`tolerance_max`
presence.

Per stage after the change:

| stage | `inspection_mode` | `sample_size` | Ac / Re | numeric rows |
|---|---|---|---|---|
| incoming | `lot_checklist` (unchanged) | AQL n | AQL | `measured_pieces` |
| in-process | `lot_checklist` (was `per_unit`) | `quality.in_process.sample_size` | 0 / 1 (unchanged) | `measured_pieces` |
| outgoing | `lot_checklist` (was `per_unit`) | AQL n (still declared on the CoC) | AQL | `measured_pieces` |

`measured_pieces` resolves from the new setting **`quality.inspection.measured_pieces`**
(default 5). The existing `quality.incoming.measured_pieces` is read as a **fallback for
one release**, then removed in follow-up work. Stage-specific overrides are not added —
nothing needs them.

Backend files:

- `api/app/Modules/Quality/Services/InspectionService.php` — add `scaffoldLotChecklist()`;
  call it from `create()` (outgoing + in-process branch) and `createIncomingFromPlan()`;
  set `inspection_mode = lot_checklist` in `create()`.
- `api/database/migrations/0564_seed_quality_inspection_measured_pieces.php` — seed the new
  key. Numbered prefix is correct here: the migration only inserts into `settings`, which no
  `2026_*` migration creates or alters. `0564` is the next free prefix (highest is `0563`).
- `api/app/Modules/Admin/Requests/UpdateSettingRequest.php` — add
  `'quality.inspection.measured_pieces' => ['value' => ['required', 'integer', 'min:1',
  'max:1000']]` alongside the existing `quality.incoming.measured_pieces` rule, or the key
  cannot be edited from the settings screen.

Frontend files:

- `spa/src/pages/quality/inspections/components/IncomingLotChecklist.tsx` →
  `LotResultPanel.tsx` (rename only; body unchanged). Its docblock already describes the
  generic behaviour.
- `spa/src/pages/quality/inspections/detail.tsx` — import and render the renamed panel for
  any stage whose mode is `lot_checklist` (the current condition already keys on the mode,
  not the stage). The `per_unit` grid branch stays for legacy rows, including the header
  Save/Complete buttons that are currently suppressed for `lot_checklist`.
- `IncomingLotChecklist.test.ts`, `computeLotChecklistVerdict.ts` — unchanged logic;
  `computeLotChecklistVerdict` keeps its role as the UI preview only, with the server still
  authoritative.

## Section 2 — One defect-count implementation, and CoC reads it

**Latent defect found while designing this.** `CoCService::assertEvidenceSupportsCertificate()`
refuses a certificate when any row has `is_pass = false`, while
`InspectionService::complete()` passes a lot when `defects <= accept_count`. So an outgoing
lot with 1 defect against Ac = 2 passes inspection and then cannot receive its CoC. The two
rules disagree today; attribute sampling makes defects-within-Ac the common case, so
Section 1 would turn a rare disagreement into a routine one.

Design:

- New `App\Modules\Quality\Support\LotDefectCounter::for(Inspection $inspection, Collection
  $rows): array{defects: int, criticalFail: bool}` — the single implementation of the
  lot_checklist verdict arithmetic:
  `defects = max(sample_defect_count, distinct sample_index of rows where is_pass = false
  and the row carries a tolerance)`, `criticalFail = any row where is_critical and
  is_pass === false`.
- `InspectionService::complete()` calls it instead of computing the same expression inline.
- `CoCService::assertEvidenceSupportsCertificate()` becomes mode-aware:
  - All rows resolved (`is_pass` not null) — unchanged, both modes.
  - No failing **critical** row — both modes.
  - `defects > accept_count` → refuse — both modes, replacing the blanket `failing > 0`.
  - `per_unit` only: keep "fewer sampled units than `sample_size`" → refuse.
  - `lot_checklist` only: `sample_defect_count` must be recorded (the certificate's own
    sample claim must exist) and at least one piece row must carry a `measured_value`.

Because every reachable certificate comes from a `passed` inspection
(`assertEligible()` requires it), the per_mode formulas can only differ where the modes'
verdicts already differ.

Files: `InspectionService.php`, `CoCService.php`, new `Support/LotDefectCounter.php`.

## Section 3 — Incoming: many lines, one screen

New route `/quality/inspections/lot-review/:grnId` — every pending incoming inspection for
one receipt, one compact row each:

- item code/name, batch quantity, sample n, Ac
- **"Checked — no defects found"** attestation toggle. Pre-filling 0 is deliberately *not*
  done: the existing panel comments that a blank stays null because "0 would claim the
  sample was counted clean". The defect-count input enables only after the toggle is on.
- critical checklist items expandable to be unticked individually
- measurement disclosure, collapsed by default: *"Measure 5 pieces × 3 dimensions"*
- **Submit all** — sequential calls to the existing per-inspection record endpoint, with a
  per-row outcome (pending / done / error with the server message). Rows that need
  measurements are blocked with a visible reason; never silently skipped.

**No new backend endpoint.** The verdict, maker-checker routing, NCR creation and GRN
settlement continue to run exactly once, in existing code.

Entry points: a button on the GRN detail page (which already computes
`incomingQcNeedsAttention`), and the inspections list when a GRN has more than one pending
line.

## Section 4 — A checklist can never be empty

`createIncomingForItem()` reads `quality.incoming.default_checklist`; when the setting is
absent or empty it seeds **zero** checklist rows and produces an inspection with nothing to
tick.

- New `App\Modules\Quality\Support\IncomingChecklist::defaults()` — the setting's array when
  it validates (non-empty `parameter_name` per entry), otherwise a code-level constant
  holding the same five items seeded by migration `2026_09_24_100100`.
- If neither yields an entry → `BusinessRuleException`, so the GRN follows its existing
  `manual_required` path with a message instead of silently receiving a dead inspection.
- `LotResultPanel` shows a warning strip when an inspection has no rows — a safety net only,
  since the backend fallback means rows should always exist.

Operational note for the reviewer: if incoming inspections show no checklist in a running
environment, check `select key from settings where key like 'quality.incoming%'` before
treating it as a UI defect — the dev database has drifted from seeders before.

## Section 5 — GRN receiving form

`spa/src/pages/inventory/grn/create.tsx` renders six optional fields (Received UOM, Lot
number, Supplier lot, Expiry, Moisture %, COA path) permanently expanded under every PO
line: a 30-line PO is 180 open inputs.

- Collapse them into a "Lot details · N" sub-row, auto-opened when the line already carries
  any of the values.
- Per-line "fill down to all lines below", plus a global expand/collapse.
- Validation unchanged — all six fields are optional today, so this is presentation only.

No lot-tracking flag exists on `Item`, so no item-driven auto-open rule is added.

## Testing

| behaviour | test |
|---|---|
| outgoing created as `lot_checklist`; mixed spec yields checklist rows + `measured_pieces` piece rows | `InspectionMeasurementContractTest` (extend) |
| `complete()` passes a lot whose defects are within Ac | `QualitySamplingBoundsTest` (extend) |
| CoC issued for a passing lot with defects ≤ Ac; refused when defects > Ac; refused on a failing critical row; refused when unresolved rows remain | `CoCEvidenceIntegrityTest` (extend) |
| `per_unit` legacy rules unchanged, including short-of-sample refusal | `CoCEvidenceIntegrityTest`, `InspectionMeasurementContractTest` |
| in-process creates checklist-shaped rows | `InProcessQcTriggerTest` (extend) |
| outgoing idempotency unaffected by the mode change | `OutgoingQcIdempotencyTest` |
| empty/missing checklist setting falls back to built-in defaults; both empty → GRN `manual_required` | `IncomingQcTriggerTest`, `IncomingLotChecklistTest` (extend) |
| lot-review submit-all records a per-row outcome and blocks measurement-bound rows | new SPA test beside the page |

`LotDefectCounter` gets a unit test covering: reported-only, failed-pieces-only, both
(maximum wins), and critical row present.

## Sequencing

1. **Sections 1 + 2 together.** Section 2 is what keeps CoCs issuable once Section 1 lands;
   shipping 1 alone would start refusing certificates for routine lots.
2. **Section 4** — standalone, smallest, unblocks the environments currently showing an
   empty checklist.
3. **Section 3** — reuses the Section 1 panel.
4. **Section 5** — Inventory only; droppable without affecting anything else.

## Risks

- **Mode change alters the evidence shape of outgoing inspections.** Mitigated by keeping
  `sample_size`, `aql_code` and `accept_count` as the AQL plan produced them, so the
  certificate's declaration is unchanged, and by the explicit CoC tests above.
- **In-process Ac = 0 means any recorded defect fails the lot.** This is existing policy,
  carried over unchanged, not introduced here.
- **During the fallback release two settings keys mean "how many pieces to measure"**, so an
  admin can edit one while the code reads the other. Mitigation: the fallback lives in a
  single resolver method that is the only reader of either key, the new key wins whenever it
  is set, and the migration seeds it — so the fallback only applies to an environment whose
  migration has not run.
