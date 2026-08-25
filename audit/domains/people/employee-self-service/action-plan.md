# M024 — People / Employee Self-Service action plan

Status: 📋 Plan Ready  
Audit date: 2026-08-24  
Overall recommendation: separate-recommended

## Ordered work

| Priority | Finding | Scope | Session recommendation | Deliverable / acceptance evidence |
|---|---|---|---|---|
| P0 | M024-F01 self-service owner-only read scope | medium | separate-recommended | Department heads retain department scope in back-office lists but receive only their own DTR, leave, and payroll rows through every self-service path; direct API calls cannot omit the owner-only mode. |
| P0 | M024-F02 certificate availability and payroll-period state | medium | separate-recommended | Catalogue and direct PDF URLs share one policy; unavailable years and non-closed/error/voided payroll rows fail closed; BIR year-end behavior is covered by API tests. |
| P1 | M024-F03 decimal-safe statutory arithmetic | medium | separate-recommended | Contribution and BIR totals use centavo/decimal-safe arithmetic, have explicit rounding rules, preserve decimal output, and pass exact fixture assertions. |
| P1 | M024-F05 regression coverage | medium | separate-recommended | PostgreSQL API and browser tests cover owner isolation, certificate years/states, profile updates, documents, partial failures, and the mobile contract. |
| P2 | M024-F04 history limit consistency | small-to-medium | same-session-ok | Loans and trainings enforce `self_service.history_limit` or pagination without hiding active/pending items; large-history tests pass. |
| P1 | M024-F06 mobile bottom navigation | medium | same-session-ok | At 390px, the documented Home/DTR/Leave/Payslip/Me navigation is persistent, accessible, feature-aware, and covered by browser assertions. |
| P2 | M024-F07 partial-query error states | small | same-session-ok | Home, profile requests, and notification catalogue failures show explicit retry/error states and do not present partial data as complete. |

## Suggested implementation sequence

1. Add failing API/E2E tests for department-head self-service isolation before changing the shared read services. Introduce a server-enforced owner-only context for M024 and keep department scope available only to back-office callers.
2. Define the certificate policy with HR/Payroll: allowed years, whether finalized or disbursed is authoritative, treatment of voided/error rows, and the BIR year-end availability rule. Enforce it in both catalogue and direct-download paths.
3. Replace float aggregation with centavo/decimal-safe arithmetic and add exact contribution/BIR fixtures, including rounding boundaries.
4. Complete the missing M024 endpoint and browser coverage, then run the focused tests against PostgreSQL and the authenticated browser suite.
5. Apply the configured history cap/pagination to loans and trainings, preserving active/pending records.
6. Add the self-service mobile shell and bottom navigation, followed by partial-failure handling for secondary queries.

## Session decision

No production-code fix is applied in this session. The material findings are not predominantly small: the two P0 findings cross authorization/read-scope and payroll/statutory state contracts, and the money arithmetic requires a separate financial correctness review. The contained UI/resource fixes should follow the separate hardening work rather than create a partial release state.
