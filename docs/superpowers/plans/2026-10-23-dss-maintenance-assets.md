# DSS R5 — Maintenance and asset decisions

## Status and boundaries

**Status: partial/blocked by decision below.** Covers D17–D19. Existing maintenance recommendations do not authorize deferrals, repairs, purchases or disposal. No detector mode promotion is included.

Current permission evidence is route/source-level only. Role names in the inventory are intended consumers; live grants and maintenance/finance policy authority were not inspected. Source visibility must be reapplied before evidence projection.

## Decision gates

| ID / state | Exact source owners and authorization evidence | Source verifier and clear condition | Source-derived demo setup | Meaningful tests | Promotion gate |
|---|---|---|---|---|---|
| D17 Partial (inventory) | `Maintenance/Services/PredictiveMaintenanceService`, `DowntimeAnalyticsService`, maintenance work orders/parts; maintenance read routes require `maintenance.view` where confirmed; exact predictive source permission and role grant unresolved. | No D17 verifier verified. Recheck exact maintenance source/job and its authoritative status; no aggregate priority disappearance proves maintenance done. | Existing maintenance work order with machine/asset, actual downtime record and spare availability where present. Do not synthesize criticality or spare data. | Stable source ID F-08; asset/machine association; downtime units; spare availability G-05; polymorphic MWO owner G-11; source deletion/permission loss. | Validate impact/criticality, spare data, ownership semantics and query scope; reviewed policy and source-derived resolved job. |
| D18 Partial | `DSS/Detectors/PreventiveMaintenanceDetector`, `Maintenance/Services/MaintenanceScheduleService`; detector requires `maintenance.view`, intended role slug `maintenance_supervisor`; live grants unresolved. | No exact verifier verified. Clear only when the same schedule's source due condition no longer holds under current approved schedule; missing schedule is unavailable. | Existing overdue preventive-maintenance schedule linked to actual machine/asset; use source due date. | Due-date scope and timezone; reschedule; duplicate schedule; machine/asset link; machine-hour G-09 limitations; deleted source unavailable. | Validate source due dates, history and owner-approved deferral constraints. No automatic deferral. |
| D19 Blocked | Asset, maintenance-cost, downtime and finance/quotation source classes were not confirmed as one authoritative decision path. Asset disposal routes include `assets.dispose.approve`; this is not repair/replace authority. | None. No clear condition until lifecycle cost, downtime, quotation, useful-life basis and authorized policy exist. | Actual asset maintenance history and vendor replacement quotation only after fields and ownership are confirmed. | Cost reconciliation/currency; useful-life assumptions; downtime calculation; missing quote; finance vs disposal approval separation; no asset write. | Owner-approved lifecycle-cost model and repair/replace authority; reconciled source history; isolated demo and reviewed policy. |

## Release protocol

Keep [readiness](../../dss/READINESS-2026-10-23.md) and [inventory](../../dss/01-decision-inventory.md) states explicit. Focused service/API/UI tests, source-derived demo, authorization review and same-source verifier are required per decision. No automatic maintenance deferral, work-order mutation, procurement or asset disposal.

