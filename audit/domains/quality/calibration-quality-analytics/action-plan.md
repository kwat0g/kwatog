# M059 — Action Plan

Final audit status: 📋 Plan Ready. No implementation fixes were made in this session: the plan contains a large RBAC/data-contract decision and several separate-recommended medium actions, so it does not meet the queue rule for same-session fixing.

| Order | Finding(s) | Ordered action and acceptance evidence | Scope | Session recommendation |
|---:|---|---|---|---|
| 1 | M059-B08 | Decide the capability role contract. Either add a Quality-owned read path for product/spec selection or explicitly map the required read permissions to the intended roles. Verify QC and production-manager role behavior with route-level 200/403 tests and a browser smoke path; do not grant broad permissions without the product decision. | large | separate-recommended |
| 2 | M059-B10 | Enforce calibration history invariants at request and service boundaries: no future last-calibration date, no next date before last date, and a documented relationship between next date and frequency. Add focused 422 and persistence tests. | medium | separate-recommended |
| 3 | M059-I01, I03 | Define frequency rescheduling semantics, then make calibration updates lock/reload the authoritative row or use an explicit optimistic-concurrency contract. Add concurrent PATCH and frequency-change tests. | medium | separate-recommended |
| 4 | M059-I04 | Choose a retired-record lifecycle: reject calibration recording for retired equipment or make reactivation explicit. Align the service comment, list action visibility, API response, and tests. | medium | separate-recommended |
| 5 | M059-I02 | Decide whether archived/inactive specs are valid historical SPC inputs. Align capability options, direct `/spc` reads, revision metadata, and role tests with the chosen policy. | medium | separate-recommended |
| 6 | M059-B09 | Add independent loading/error/retry handling for the capability options query and define whether results can render without thresholds. Preserve the successful study result and cover options failure plus study success in a page test. | small | same-session-ok |
| 7 | M059-M02 | Add focused SPA tests for calibration, capability, and the current Quality analytics dashboard, plus browser role checks for QC and production-manager access, calibration manage actions, no-data states, and retry states. | medium | separate-recommended |
| 8 | M059-P05, P06 | Correct the defect Pareto semantic formatter/label without breaking the shared downtime chart, and replace calibration form raw loading/error text with the standard query-state components. Add UI assertions for displayed units and recovery affordances. | small | same-session-ok |

## Re-audit gate

Keep M059 at 📋 Plan Ready until the role/data-contract decision, calibration date/lifecycle/concurrency decisions, and archived-SPC policy are recorded and implemented. Then run the isolated backend suite with `DB_DATABASE=ogami_test_m059_roll_b`, SPA lint/typecheck, and the M059 browser role paths before considering ✅ Verified.

## Session evidence

- Focused backend verification: 36 tests / 147 assertions passed on `ogami_test_m059_roll_b`.
- Targeted SPA ESLint and `npm run typecheck` passed.
- PHP syntax lint passed for the reviewed M059 sources and focused tests.
- No implementation or dependency files were changed; `fix-log.md` therefore required no new fix entry.
