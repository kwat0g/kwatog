# M009 — Global Search action plan

Status: 📋 Plan Ready
Audit date: 2026-08-27
Overall recommendation: separate-recommended

## Ordered actions

| Order | Finding | Size tag | Session tag | Acceptance evidence |
|---:|---|---|---|---|
| 1 | M009-F09 — enforce the owning module feature flag for every search group | **medium** | **separate-recommended** | With each `modules.*` flag disabled, API and palette omit that group's records; enabled groups remain searchable; matrix tests pass. |
| 2 | M009-F10 — invalidate same-user recents after permission/module changes | **medium** | **separate-recommended** | Permission override and module-toggle refreshes remove or revalidate affected recents; no stale label/sublabel remains visible; localStorage tests pass. |
| 3 | M009-F09/F10 — add negative authorization and feature regression coverage | **medium** | **separate-recommended** | Backend tests cover disabled features and current permissions; SPA tests cover refreshed auth state, recent invalidation, and hidden API groups. |
| 4 | M009-F14 — define and enforce archived related-label behavior | **medium** | **separate-recommended** | Live parent plus soft-deleted child fixtures have an explicit, consistent result; no archived label is silently presented. |
| 5 | M009-F13 — make query budget measurable and choose production search strategy | **large** | **separate-recommended** | Runtime query/latency budget is enforced; production-sized plans justify prefix, trigram, or full-text behavior; cross-module migration ownership is recorded. |
| 6 | M009-F11 — include employee middle names | **small** | **same-session-ok** | A middle-name-only fixture appears in global search with stable relevance and the existing row scope. |
| 7 | M009-F12 — normalize before minimum-length validation | **small** | **same-session-ok** | Padded one-character and whitespace-only queries consistently return 422 or a documented normalized response; UI/API behavior agrees. |
| 8 | M009-F15 — add a modal focus trap | **small** | **same-session-ok** | Tab and Shift+Tab remain within the palette; Escape closes and restores focus; browser/accessibility test passes. |
| 9 | M009-F16 — resolve SQLite wildcard semantics | **small** | **separate-recommended** | Supported-driver behavior is explicit and literal `%`/`_` regression tests pass, or SQLite is removed from the helper contract. |

## Implementation sequence

1. Establish a single group-to-feature map and server-side gate. Keep the permission and
   row-scope checks separate: a user must both have the permission and be in an enabled
   module.
2. Define the client cache invalidation contract for permission/module events. Redact or
   revalidate persisted records before rendering them after an auth refresh.
3. Add the negative backend and SPA tests before changing the remaining search semantics.
4. Decide the archived related-label policy with the owning resource modules and cover
   live-parent/deleted-child cases.
5. Measure worst-case fan-out and latency on representative data; only then choose an
   index/search strategy and executable budget.
6. Complete the middle-name and normalized-query fixes, then the focus trap and driver
   contract work.
7. Re-run the unique-DB backend suite, focused SPA tests/typecheck/lint, authenticated
   browser checks, and the production-sized benchmark before requesting Verified status.

## Gate decision for this audit session

No implementation fix is applied. The plan contains P1 authorization/privacy work,
medium/large actions, and six `separate-recommended` actions versus three
`same-session-ok` actions. Total scope is not small, so the required gate for a
same-session fix is not met. Only module audit documents are changed in this session.
