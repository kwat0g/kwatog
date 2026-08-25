# M011 — Documents & Exports action plan

Status: 📋 Plan Ready  
Audit date: 2026-08-24  
Overall recommendation: separate-recommended

## Ordered work

| Priority | Finding | Scope | Session recommendation | Deliverable / acceptance evidence |
|---|---|---|---|---|
| P0 | M011-F01 authoritative export column contract | medium | separate-recommended | Every preview, download, saved preference, schedule, and background run rejects unknown or unauthorized columns; HR export cannot return TIN, government IDs, or bank-account fields without an explicit approved capability; negative tests pass. |
| P1 | M011-F02 scheduled-export capability and replay authorization | medium | separate-recommended | Module permission, implementation, column policy, recipient policy, and stored-config revalidation are shared with direct exports; custom-role tests prove a schedule cannot bypass direct export permission. |
| P1 | M011-F03 module registry/runner/UI alignment | medium | separate-recommended | Each advertised module has a registered column catalog, runner class, filters, permission, and feature test, or its UI/permission key is removed until implemented; inventory export has a working authenticated flow. |
| P1 | M011-F05 official PDF vault integration | large | separate-recommended | Accounting, Purchasing, Quality, Supply Chain, CRM, and bulk company PDFs use the shared renderer/vault with correct entity/type/confidentiality metadata and central access tests; self-service certificates remain an explicit documented exception. |
| P1 | M011-F04 scheduled-export create/edit UX | medium | separate-recommended | HR/inventory column selection can create and edit schedules with current filters, recipients, frequency/time, next-run feedback, archive/restore, and an authenticated browser test. |
| P1 | M011-F09 security and lifecycle regression suite | medium | separate-recommended | Negative field/permission/owner tests, successful schedule execution, queue failure, missing blob, central document list, and PDF family tests run in CI; browser flows use a live API/SPA. |
| P2 | M011-F06 entity-scoped document surface | medium | separate-recommended | Detail pages mount a guarded document list using a canonical entity-scoped endpoint; admin document enumeration remains separately permissioned; client URL contracts are accurate. |
| P2 | M011-F07 resource bounds and durable attachments | medium | separate-recommended | Export row/byte limits and timeout behavior are explicit; large exports use chunked/disk-backed generation where possible; queued mail references a durable private artifact rather than embedding unbounded base64. |
| P2 | M011-F08 retention and orphan reconciliation | medium | separate-recommended | Document-type retention is documented, dry-run/reconciliation reports missing/orphaned blobs, deletion is auditable and recoverability is understood, and the command is scheduled/monitored. |
| P2 | M011-F10 renderer hardening | small | same-session only after semantic fixes | Dompdf PHP/JavaScript features are disabled unless justified by a reviewed template; configuration and escaping assertions are present. |

## Suggested implementation sequence

1. Freeze the authoritative export-module contract: implementation class, permission, allowed columns/resolvers, filter schema, recipient policy, and resource limits. Apply it to direct and scheduled paths before adding more modules.
2. Add the P0/P1 negative tests for sensitive columns, custom-role scheduling, invalid persisted columns, and missing runner implementations. Remove or finish unsupported UI/module keys.
3. Add the scheduled-export create/edit UI and live browser flow, then add successful queue/next-run and mail-failure tests.
4. Inventory all official PDF routes and migrate company records to the shared renderer/vault. Preserve and document the personal self-service exception separately.
5. Connect the central document list to entity detail pages with an explicit scoped endpoint and access tests.
6. Set resource limits, move large queued artifacts to private durable storage, define retention/reconciliation, and harden Dompdf after template review.
7. Run the full relevant backend suites, SPA typecheck/unit/build gates, authenticated browser audit, production-like PDF smoke flows, migration rollback rehearsal, and an orphan/reconciliation report before moving M011 to Verified.

## Audit-session decision

No implementation fix is applied in this session. The findings include a high-severity data-exposure boundary, a cross-path authorization refactor, missing export implementations/UI, and a broad PDF lifecycle migration. The work is not predominantly small; an isolated same-session edit would leave the highest-risk paths unresolved.
