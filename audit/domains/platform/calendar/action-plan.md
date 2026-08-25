# M010 Calendar — Action Plan

**Decision:** Plan Ready. No product fixes were made in this session.

The first three workstreams cross module authorization and privacy boundaries; the UI work also needs coordinated API contract and regression coverage. They are therefore recommended as separate implementation sessions rather than a partial same-session patch.

## Planned work

### 1. Reconcile calendar visibility with destination-module authorization

- **Findings:** CAL-01, CAL-02, CAL-03, CAL-04, CAL-16.
- **Scope:** Large.
- **Session recommendation:** Separate recommended.
- **Plan:** Define a per-layer visibility matrix. Reuse leave department/own scoping; prevent arbitrary or invalid department filters from becoming unscoped; separate own-payroll from payroll-period visibility; accept both broad and narrow delivery permissions; and return a record link only when the caller can open the destination. Add role-based API tests for employee, department head/approver, HR, payroll, and warehouse users.

### 2. Correct source-record and time-window selection

- **Findings:** CAL-05, CAL-06.
- **Scope:** Medium.
- **Session recommendation:** Same-session-ok after workstream 1’s visibility contract is agreed; otherwise separate recommended.
- **Plan:** Exclude soft-deleted holiday, leave, delivery, and production work-order rows. Replace maintenance point-in-range checks with interval-overlap logic and define null/open-record behavior. Add regression fixtures for archived rows and records spanning both sides of a requested range.

### 3. Make range results explicit and deterministic

- **Findings:** CAL-07, CAL-08.
- **Scope:** Medium.
- **Session recommendation:** Same-session-ok.
- **Plan:** Decide on caps versus pagination/agenda retrieval. If caps remain, return per-layer counts and truncation metadata. Normalize layer input with uniqueness validation, report effective authorized layers, and make event IDs consistent and opaque.

### 4. Repair the calendar’s responsive and accessible interaction model

- **Findings:** CAL-09, CAL-10, CAL-11, CAL-12, CAL-13, CAL-14.
- **Scope:** Medium.
- **Session recommendation:** Separate recommended.
- **Plan:** Preserve date-only values as local calendar dates; use a true seven-column layout with a deliberate narrow-screen alternative; add semantic date labels/today state and contextual event names; meet project hit-target guidance; make overflow interactive; and add options-query retry/error states with visible refresh feedback. Cover the behavior with SPA/component or E2E tests.

### 5. Add primary navigation and supported filtering

- **Findings:** CAL-15, CAL-16.
- **Scope:** Small to medium.
- **Session recommendation:** Same-session-ok for navigation after authorization behavior is settled; separate recommended for department filtering.
- **Plan:** Add a permission-aware Calendar item to the Sidebar. Only add a department selector after the visibility matrix defines which departments each role may query.

### 6. Establish module-specific regression coverage

- **Findings:** Discovery gap; all hardening findings.
- **Scope:** Large.
- **Session recommendation:** Separate recommended.
- **Plan:** Add API feature coverage for authorization, scoping, soft deletes, range overlap, caps, invalid hashes, duplicate layers, and link exposure. Add frontend coverage for timezone-safe placement, narrow-screen layout, overflow activation, semantic labels, options failures, and navigation.

## Recommended implementation order

1. Agree the visibility/link contract and open questions.
2. Implement API authorization and source-query corrections.
3. Add API regression tests.
4. Repair SPA layout/accessibility/error states and add frontend coverage.
5. Add navigation and any authorized department filter.

## Session disposition

No fixes are applied now. The majority of risk-bearing work is separate-recommended, and the combined scope is not a contained low-risk change. Release this module as `📋 Plan Ready`.
