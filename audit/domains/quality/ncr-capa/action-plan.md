# Action Plan — Quality / NCR + CAPA (M057)

Status: 📋 Plan Ready  
No source fixes were applied during this audit.

| Order | Findings | Ordered work | Scope | Session |
|---:|---|---|---|---|
| 1 | F-001, F-002 | Define escalation-eligible states; claim/lock candidates; persist a durable tier-delivery/idempotency record; handle empty audiences and notification failures; add in-progress, failure, and concurrency tests. | Large | separate-recommended |
| 2 | F-003, F-004, F-005 | Make inspection-linked NCR creation database-idempotent; replace description-prefix recurrence with a canonical structured defect signature; move recurrence linking/notifications to retryable after-commit work. | Large | separate-recommended |
| 3 | F-006 | Add CAPA transition rules and lock the NCR/action during verification. Permit only corrective/preventive actions on eligible closed NCRs and reject invalid/repeated transitions. | Medium | separate-recommended |
| 4 | F-007, F-014, F-015 | Design notification cadence/deduplication and links; expose owner/due-date assignment; add full CAPA service/controller tests including rollup and repeat-verification cases. | Large | separate-recommended |
| 5 | F-008, F-010 | Build the CAPA due/overdue queue, detail verification panel, and bulk-close selection/result workflow with permission-aware UI and cache invalidation. | Large | separate-recommended |
| 6 | F-009, F-016 | Normalize return-to-supplier notifications to the standard typed payload and post-commit delivery; make required scrap/rework work-order creation fail visibly or become a durable retryable state. | Large | separate-recommended |
| 7 | F-013 | Confirm whether production managers are observers or NCR/CAPA actors; align permissions, alert recipients, and route behavior with that decision. | Small | separate-recommended |
| 8 | F-011, F-012 | Add typed list-query validation and reconcile generated/frontend enum/resource contracts. | Small | same-session-ok |

## Acceptance gates

- An NCR with containment only remains eligible for the intended escalation policy after entering `in_progress`.
- A tier is not consumed without a durable delivery/outbox record, and repeated/concurrent runs are idempotent.
- Concurrent inspection failure handling yields exactly one NCR per inspection.
- Auto-generated equivalent defects link as recurrences using structured/canonical data.
- CAPA verification rejects containment/open/terminal-invalid transitions and records a valid audit history.
- Due alerts are deduplicated, actionable, and linked to the NCR/action.
- QC and the approved manager role can discover and complete CAPA verification in the SPA.
- Scrap/rework close cannot silently lose its required production work order.
- Focused tests run against a reachable PostgreSQL service and cover each acceptance gate.
