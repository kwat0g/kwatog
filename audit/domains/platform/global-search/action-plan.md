# M009 — Global Search action plan

Status: 📋 Plan Ready  
Audit date: 2026-08-24  
Overall recommendation: separate-recommended

## Ordered work

| Priority | Finding | Scope | Session recommendation | Deliverable / acceptance evidence |
|---|---|---|---|---|
| P0 | M009-F01 shared row-level visibility contract | medium | separate-recommended | Search, list, show, and linked routes use the same user-aware scope; department heads cannot enumerate cross-department employees or unrelated purchase orders; negative API tests pass. |
| P1 | M009-F02 TIN redaction and recent-item privacy | small-to-medium | separate-recommended | View-only customer/vendor search never returns raw TINs; recent sublabels contain no sensitive identifiers; API and browser localStorage tests pass. |
| P1 | M009-F03 archived-record policy | medium | separate-recommended | Default search excludes soft-deleted records across all searchable models; any archived search is explicit, authorized, and tested. |
| P1 | M009-F08 authorization and failure-path regression suite | medium | separate-recommended | Backend tests cover route/feature/permission gates, row scope, masking, soft deletes, and result contracts; SPA tests cover error, permission, mobile, and persistence behavior. |
| P2 | M009-F04 wildcard, fan-out, and search-index contract | medium | separate-recommended | Search syntax is documented and safely escaped; latency/query budgets, retry behavior, and representative query-plan benchmarks are recorded; suitable indexes or a deliberate search backend are in place. |
| P2 | M009-F05 deterministic relevance ordering | small-to-medium | same-session only after security fixes | Exact/prefix/name relevance is deterministic and stable under ties; exact identifiers are preferred within each group. |
| P2 | M009-F06 error and retry UX | small | same-session only after security fixes | 403, 429, and 5xx responses render distinct recoverable states without misleading empty results or unlabelled stale rows. |
| P2 | M009-F07 mobile and accessibility entry point | small | same-session only after security fixes | Search is reachable on narrow screens, focus is restored to the opener, and a narrow-viewport browser test passes. |

## Suggested implementation sequence

1. Inventory every search group and make its visibility policy explicit. Prefer a shared user-aware query/policy adapter or a service method that the search and list/show paths both call; remove the current broad-permission-only branches.
2. Add department-head and view-only fixtures before implementation. Prove cross-department employees and purchase orders, archived records, and customer/vendor TINs are all excluded or redacted as intended.
3. Remove TIN from the result/recent contract or implement the exact resource masking policy in one shared presenter. Review all fields that may be persisted by recentItemsStore.
4. Add the backend and SPA negative/failure tests, then run them with a writable Vite cache and a live authenticated API/SPA environment.
5. Define matching semantics and resource budgets. Escape literal wildcard characters, add deterministic relevance ordering, choose indexes/full-text/trigram support based on measured plans, and set explicit retry/rate-limit behavior.
6. Finish error states, mobile opening, focus restoration, and accessibility behavior after the server contract is stable.
7. Re-run the focused backend suite, SPA typecheck/lint/unit/build gates, authenticated browser audit, soft-delete cases, and representative performance measurements before moving M009 to Verified.

## Audit-session decision

No implementation fix is applied in this session. The findings include a P0 row-authorization bypass, a high-severity TIN exposure, an archived-record contract defect, and missing regression coverage. The work is not predominantly small and an isolated same-session edit would leave the highest-risk paths unresolved.
