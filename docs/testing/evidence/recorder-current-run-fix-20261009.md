# Current-run production recording display

## Cause

WO-202610-0009 was within its approved run. Its assigned recorder could not record output because all three issued material lines still awaited Warehouse handover. The API returned `material_handover_pending`, while the page replaced that specific blocker with generic late-production guidance. The report permission identifies the designated recorder; it does not establish that the production interval has finished.

## Change

The assignment page now displays the actual recording blocker. The earlier-production entry remains hidden while the saved production finish is in the future. Saved drafts and unresolved submissions remain accessible for recovery, including after a run extension. Existing server validation, material handover requirements, recorder authorization, and output limits are unchanged.

## Verification

- The two current-run regressions failed before the fix, reproducing both the expanded and collapsed inappropriate earlier-production entry.
- All 23 tests in the assignment page and earlier-production component passed after the fix, including expired-run reporting and unresolved submission recovery.
- Chromium checks at 390 and 1440 pixels verified the Warehouse message, hidden earlier-production entry, disabled quantity before handover, enabled quantity after simulated handover, and rejection of 201 pieces against a 200-piece target.
- ESLint passed for the four affected source and test files. The TypeScript and Vite build passed.
- A live rollback-only controller probe at 03:00:21 Asia/Manila verified `material_handover_pending`, three pending material lines, and an approved finish of 04:52:31. No business action was performed.

Artifacts are retained in `/tmp/recorder-current-run-20261009/`. Browser checks used mocked API responses; physical handover and actual production output were not performed by the agent.
