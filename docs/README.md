# OGAMI documentation

This directory contains current product documentation, operational runbooks,
defense evidence, and the authoritative 2026-08-13 system audit. Superseded
audits, completed task queues, prompt templates, and implementation plans have
been removed.

## Current audit

- `SYSTEM-AUDIT-EXECUTIVE-SUMMARY-2026-08-13.md` — concise disposition and release recommendation.
- `SYSTEM-MODULE-AUDIT-2026-08-13.md` — current module and cross-module assessment.
- `SYSTEM-AUDIT-FINDINGS-2026-08-13.md` — F-001 through F-038 findings and closure overlay.
- `SYSTEM-AUDIT-FINDING-LIFECYCLE.json` — authoritative machine-readable status and evidence scope.
- `AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json` — verification command mapped to every finding.
- `SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md` — retained roadmap and decision history.

The current audit posture is 36 verified, one mitigated (F-032), and one open
external-evidence gate (F-030).

## Engineering and operations

- `PATTERNS.md` — backend and SPA implementation patterns.
- `DESIGN-SYSTEM.md` — UI tokens and component rules.
- `SCHEMA.md` and `SEEDS.md` — persistence and reference-data documentation.
- `PROCESS-FLOWS.md` — business workflows and manual checks.
- `DEPLOY.md` and `RESTORE-DRILL.md` — deployment and recovery runbooks.
- `AUTO-BROWSER-TESTS.md` and `QA-MATRIX.md` — browser and device verification.

## Product and defense

- `USER-MANUAL.md` — user-facing workflows.
- `DEMO-SCRIPT.md` — live defense walkthrough.
- `DEFENSE-TRACEABILITY.md` — adviser requirement-to-implementation mapping.
- `defense-screenshots/` — current defense evidence.
- `atelier-baseline/` — retained before/after visual baseline.

When adding documentation, update this index and replace an older document
instead of creating another overlapping report or task list.
