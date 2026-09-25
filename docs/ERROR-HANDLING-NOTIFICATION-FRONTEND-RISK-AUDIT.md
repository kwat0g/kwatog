# Error Handling, Notification, and Frontend Risk Audit — OGAMI ERP

## Objective

Audit error handling, user-facing notifications, and frontend-specific risk/bad-practice patterns across all 17 modules, then implement fixes. Each fix ships as its own independently revertable PR; do not commit directly to `main`.

## Scope

- All 17 modules: Order-to-Cash, Procure-to-Pay, Hire-to-Retire chains, Quality, Maintenance, and Dashboard.
- Backend (Laravel exceptions, validation, HTTP status codes, authorization) and frontend (React/TypeScript component logic, state, forms, real-time).
- Discovery-based: search the codebase broadly rather than limiting findings to known issues.

## Tasks

### 1. Error handling audit

Identify per module: unhandled exceptions/uncaught promise rejections; silent failures without user feedback or logging; generic catch-alls masking causes (such as blanket `catch (e) {}` or hardcoded `"Something went wrong"`); incorrect/inconsistent HTTP status codes; missing validation before mutations/database writes; and unguarded null/undefined access.

Log every finding with module, file/line, root cause, and severity: blocks a workflow, degrades UX, or cosmetic.

### 2. Toast/notification message correctness

Fix every error-path toast that is generic, misleading, or missing. Each message must name the actual failure, be actionable when possible, be one short sentence without stack traces/raw exception text/jargon, and match the app's toast style. If the backend lacks enough detail, fix the source rather than guessing on the frontend.

### 3. Replace raw ID-entry fields with dropdown/search selectors

Find every form requiring a related record (supplier, item, employee, customer, etc.) by raw ID. Use a name-labeled standard dropdown for small/bounded datasets and a searchable/autocomplete selector for datasets of hundreds or more. Disambiguate duplicate names (for example, `Name (Code)` or `Name — Location`). Store the ID internally; users must not see or type it. Confirm or add a lightweight backend list/search endpoint; do not fetch a slow full table in the frontend.

### 4. Authorization on record access (IDOR)

For every endpoint accepting a record ID, independently verify that the logged-in user may access or modify that specific record. A selector is cosmetic security: crafted API requests can still submit IDs. Keep this security fix separate from Task 3, even where they touch the same form.

### 5. Standardize inconsistent solutions

Find cases where modules solve the same problem differently (error handling, toast style, ID selection), and log each inconsistency. Consolidate recurring patterns into shared solutions (for example, one API-error-to-toast mapping utility) instead of patching each local copy.

### 6. Verify server-side error logging

For every Task 1 fix, verify that the actual exception still reaches Laravel's log for debugging, separately from the sanitized user message.

### 7. Flag duplicate feature implementations

Find operations implemented more than once with diverging logic (for example, creating a purchase order or looking up a supplier). Consolidate duplicates as part of the fix so copies cannot drift.

### 8. Frontend risk and bad-practice audit

Log findings with module, file/line, category, and severity. Fold recurring categories into Task 5's consolidation work.

#### A. Double submission and race conditions (highest priority)

- Prevent rapid repeat submission of financial/inventory forms (POs, orders, invoices) while requests are in flight.
- Find optimistic UI updates without rollback on failure.
- Find search/autocomplete requests without `AbortController`; prevent out-of-order responses replacing newer results.

#### B. Cosmetic-only RBAC

- Cross-check conditionally hidden buttons, menus, and routes against backend enforcement (coordinate with Task 4).
- Find route guards that redirect while protected components mount and fetch first.

#### C. Real-time (Reverb/WebSocket) reliability

- Find missing reconnect/backoff logic, stale open tabs, and event handlers accumulating because `useEffect` cleanup is missing (including repeated toasts/updates).

#### D. Destructive actions

- Find delete/cancel/void actions without confirmation.
- Find unsaved forms, especially multi-step ERP forms, without a navigation warning.

#### E. Data and type safety

- Find excessive TypeScript `any` on API responses.
- Find missing shared/generated types between Laravel responses and frontend consumers.
- Find client-side totals, stock, or tax calculations duplicating backend logic and at risk of drift.

#### F. Performance and maintainability

- Check code-splitting/lazy loading across the 17 modules and initial bundle size.
- Find unnecessary list/table re-renders, large lists without pagination/virtualization, dead code/dependencies, production `console.log`/debug statements, and hardcoded API URLs/magic numbers/role names that should be configuration or constants.

#### G. Consistency

- Find inconsistent loading/empty/error states, date/currency/number formatting, and validation feedback (inline, toast-only, or silent) across modules.

## Frontend improvement/polish track (non-blocking)

Log separately; do not let these items compete with the deadline:

- Accessibility gaps (keyboard navigation, ARIA labels, contrast), including considerations for auditable IATF 16949 tooling.
- Responsive gaps on factory-floor kiosks/tablets.
- Print/export formatting inconsistencies.
- Opportunities to consolidate duplicate UI components.

## Deliverables

- One PR per fix, independently revertable; no direct commits to `main`.
- Task 4 security work separate from Task 3 UI work.
- Findings log separate from fix PRs so it does not block the deadline.
- Systemic Task 5/7/8 consolidations may ship as one umbrella PR.

## Out of scope

New feature work found during the audit; route it to the improvement/polish track instead.
