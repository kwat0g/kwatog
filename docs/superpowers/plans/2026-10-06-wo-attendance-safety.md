# WO attendance safety implementation plan

Approved scope: `docs/audits/wo-attendance-scheduling-20261006.md` and the user's approval. Implement inline in the shared workspace; preserve unrelated edits. No additional permissions, dependencies or database schema are required.

- [x] Add focused regression coverage for early punches, archived attendance, recorded clock-out inside an interval, later shifts, presence-prioritized suggestions, late recorder authorization and changed attendance reminders.
- [x] Correct shared presence and assignment rules. Include selected shift interval and attendance context in worker options and suggestions. Prefer verified current workers for immediate proposals without changing approved assignments.
- [x] Keep ambiguous AM/PM leave on night shifts conservatively blocked with an explicit HR-review explanation; reuse date ownership and do not invent half-day clock times.
- [x] Recheck recording permission against the current recorder session. Preserve existing Start presence evidence for missing biometric imports and existing manager recording with an explicit reason for late reporting. Do not bypass WO, equipment, QC, quantity or material checks.
- [x] Reuse minute reminders to notify management of changed crew eligibility. Use deduplication and current schedule/crew; do not automatically reassign approved crews.
- [x] Show worker shift times, selected-date attendance context and provisional labels. Refresh open Start checks every 30 seconds and invalidate stale physical-presence confirmation.
- [x] Run affected backend/frontend tests, PHP formatting, ESLint, TypeScript/build and headless role-based browser checks. Verify live business records remain unchanged by browser validation. Update audit evidence and explain final behavior.

Verification and behavior: `docs/testing/wo-attendance-safety-20261006.md`. Final gate: 97 backend tests, 551 assertions; 32 frontend tests; four-role headless Playwright; formatting, lint, TypeScript and production build passed.
