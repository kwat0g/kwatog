# Quality batch safety

Implement a single batch decision without manufacturing measurement evidence. A PASS confirms the required checks on the planned sample and zero observed defective pieces. A FAIL records unique defective pieces, problem, critical severity, and segregation; the pinned acceptance plan derives the actual verdict. Unknown coverage/count stays null. Known defective pieces never contribute to released capacity.

A failed verdict holds the batch and remains immutable. Reuse NCR disposition to record full-batch sorting/rework evidence and an independent approval. Release only the verified good quantity; keep the original failure, held quantity, sample count, and confirmed rejected quantity distinct. Reuse existing delivery capacity and MRP calculations. Do not add per-piece forms, new workflow engines, or fabricated SPC/certificate data.

1. Add nullable coverage and sorting evidence fields, validation, and database quantity constraints. Preserve existing records and rollback.
2. Implement shared counting/review guards and a batch submission endpoint. Prevent legacy checklist completion from bypassing sample coverage.
3. Replace checklist inputs with one batch decision and readonly requirements, preserving the record flow/context/SPC layout.
4. Extend existing NCR disposition with independently approved recovery; apply the same release eligibility to delivery and MRP. Require evidence before quarantine transfers to good stock.
5. Add focused regressions for coverage, critical failures, acceptance thresholds, distinct defective pieces, immutable failure, sorting quantities/approval, and delivery limits. Verify PHP tests in the dedicated test database, frontend tests/build, and desktop/mobile layout.

No existing inspection or inventory data will be rewritten. Existing numeric evidence requirements for certificates remain enforced.

The implementation reuses the existing inspection sample/count fields and NCR material decision. New fields are nullable, with no backfill of unknown evidence. Delivery options, reservations, dispatch, and MRP share the outgoing release predicate. Outgoing recovery supports scrap or completed rework; supplier returns retain their incoming receipt lineage. Inspection FAIL cannot be overridden into PASS, and settled recovery evidence cannot be cancelled or rewritten.

Focused regressions include full sampling coverage, unique defective-piece arithmetic, critical severity, acceptance thresholds and segregation, HTTP value normalization, original-maker and sorting approval separation, recovery immutability, delivery 99 versus 100, MRP replacement shortfall, incoming full/partial caps, and NCR-only quarantine guards. The five inspection stages and the 100-piece sample were previewed on desktop and mobile without submitting or resetting records.

Validation completed: 130 API regression tests (564 assertions), 17 frontend tests, focused PHPStan and ESLint, production build, and 12 desktop/mobile browser previews. The additive migration was applied. The 100-piece sample remains draft with unknown coverage/count; no sample results were submitted during browser verification. Local test credentials were removed after the isolated test run.
