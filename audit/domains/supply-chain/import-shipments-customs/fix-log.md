# M043 — import-shipments-customs fix log

No application source fixes were applied during the 2026-08-25 audit session. The module remains 📋 Plan Ready because the dominant work requires decisions around PO/shipment/GRN ownership, customs evidence gates, supplier-portal convergence, landed-cost accounting, and archive/file retention.

Verification recorded in `audit-report.md`:

- Focused shipment, status-concurrency, ImpEx PDF, and private document suite: 26 tests, 53 assertions passed.
- SPA TypeScript typecheck passed.
- Migration status and Supply Chain route inventory were checked.
- SPA API-route audit was attempted but the existing script failed before producing results with `ERR_STREAM_NULL_VALUES`.

The ordered remediation work is in `action-plan.md`.

## 2026-08-25 implementation session

No application source fixes were applied. The first ordered plan item
(`M043-F001/F002`) is blocked on a human decision about the authoritative
PO → shipment → customs-evidence → GRN/AP lifecycle: permitted PO states,
multiple shipment legs, required customs evidence, and the durable GRN
handoff/linkage contract. The later items are intentionally pending because
they depend on that contract or on separate business decisions:

- `M043-F007`: supplier-portal shipment/document source of truth and
  reconciliation/provenance policy.
- `M043-F004`: landed-cost inputs, cent allocation, inventory valuation, and
  AP treatment.
- `M043-F003`: whether shipment Incoterm overrides or inherits the PO value.
- `M043-F006`: terminal archive policy and document-file retention/recovery.
- `M043-F005`: container ownership and lifecycle in the shipment journey.
- `M043-F008`: terminal document versioning and mutation policy.

Before/after: application files unchanged; module remains `🔁 Needs Re-audit`
pending those decisions. No finding was marked fixed or re-verified.
