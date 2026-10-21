# DSS Progress (single source of truth — resume from here)

## Status: Wave 0 COMPLETE (6 stacked PRs #200–#205). Wave 1 ready to start.

- `00-discovery.md` — done 2026-10-12 (63 cron triggers, ~40 logics, E2E traces, RBAC, tech).
- `01-decision-inventory.md` — done (33 decisions scored; top D13/D26=25.0; 12 process gaps).
- `02-plan.md` — done, approved on recommended defaults 2026-10-12.
- `FINDINGS.md` — 15 items (F-01..F-15), separate-track PRs.

## Wave 0 shipped (all shadow-safe, all tests green)

- W0-1 (#200): 7 tables (partial-unique open-dedupe index), DetectorInterface/
  Emission/Registry, DssParameterService (audited writes, shadow defaults),
  dss.view/manage/admin + modules.dss (migration + seeder parity). 15 tests.
- W0-2 (#201): runner (dedupe upsert every mode, per-detector isolation + run
  logs), transparent scorer, `dss:run` hourly cron, DssServiceProvider. 25 tests.
- W0-3 (#202): lifecycle API (lazy expiry/snooze-resurface, one event per
  transition, viewed deduped/user/day), visibility = dss.view + owner-permission
  + advisory/assisted; shadow/unknown hidden (403 direct). 36 tests.
- W0-4 (#203): /decision-center + /:id (severity groups, Why-this rationale,
  impact/confidence badges, accept/dismiss+reason/snooze+date, outcome),
  sidebar entry. Vitest helpers; tsc/eslint/prettier clean.
- W0-5 (#204): dss:snapshot-stock daily 03:45 (G-01), MasterHygieneDetector
  `dss.master_hygiene` in shadow (D27), DemandBasis F-02 (legacy sets preserved,
  per-consumer keys, UpdateSettingRequest allow-list). 70 tests incl. rewired suites.
- W0-6 (#205): admin API (fleet state, enable/mode/run-now, engine read/write
  split manage/admin, audited params) + /admin/dss page; F-13 guard test +
  audit:dss-routes parity (exit 0); F-12 verified-no-code (SettingsGovernanceTest
  + DssParameter audit). 53 DSS tests.

## Decisions covered / detectors live

- Live (shadow): D27 master hygiene (`dss.master_hygiene`).
- Ready for Wave 1 (priority order): D13 (NCR/CAPA/8D), D25 (approval bottleneck),
  D26 (stuck documents), D09 (collection priority), D03 (at-risk orders), D21
  (pre-payroll), D07, D12, D22.
- Existing logic reserved for reuse: MRP engine, alert engine, 16 bottleneck
  detectors, replenishment+SS+ABC, forecasting+actuals, supplier scorecard,
  3-way match, AR dunning, NCR/8D/CAPA escalations, SPC/COPQ, predictive
  maintenance, 11 KPIs, Action Center.

## Gaps / blockers

- Process gaps G-01 (snapshots now writing; history accumulates from 2026-10-12),
  G-02..G-12 open (see 01).
- F-15 new: projection movement types `consume`/`production_issue` match no enum
  case (effectively material_issue-only) — preserved verbatim, fix = Wave 1 behavior PR.
- Single-shared demand set still needs owner call (F-02 follow-up).
- PR stack merges bottom-up: #200 → #201 → #202 → #203 → #204 → #205
  (each base retargets to main as its parent merges); docs #199 independent.

## Next

1. Merge stack bottom-up (CI + review per PR).
2. Wave 1 detectors, each shadow → advisory with precision in `evaluation.md`.
3. F-01 (true price variance) + G-02 (price history) before D02.
