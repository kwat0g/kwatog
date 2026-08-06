# Ogami ERP — Reusable Full-System Audit Prompt

Your original ask, rewritten so it produces verified, actionable output instead of a wall of
plausible-sounding findings. Paste the block below as-is; edit the bracketed bits.

---

## The prompt

> Audit the Ogami ERP monorepo at `/home/kwat0g/Desktop/kwatog`. Read `CLAUDE.md` first — it
> defines the three business chains, the security rules, and the explicit **NOT BUILDING**
> cut-scope list.
>
> **Scope — audit all of it, but report in these buckets:**
>
> 1. **Stuck processes** (highest priority). For every state machine in the three chains, build
>    the real transition table from code, then find states a record can enter with no exit —
>    no service method, no UI action, no approver. Frame each as a real-world scenario:
>    *"Customer rejects delivery on a partially-invoiced SO → SO sits in `partially_delivered`
>    forever because X."*
> 2. **Process gaps** — missing reversals, corrections, cancels, and exception queues. Anywhere
>    a human can make a normal mistake and the system offers no way back.
> 3. **Bugs** — money/GL correctness, transaction boundaries, idempotency, race conditions,
>    enum drift, null/division-by-zero in analytics, date/period boundaries, N+1.
> 4. **Security** — authorization coverage per route, IDOR (self-service, B2B portals, edge
>    guards), approval bypass, privilege escalation, mass assignment, injection, file handling,
>    secrets, PII exposure.
> 5. **Missing features** — only where absence *stops work*. Anything on the NOT BUILDING list
>    is out of scope and must not be reported.
> 6. **Dead / nonsense features** — vanity screens, unreachable pages, endpoints with no caller,
>    duplicate implementations, unused tables/permissions. I already suspect the admin
>    *Compare roles* page and the *SoD* page. For each candidate give a removal-safety verdict
>    and full blast radius, then **remove the ones marked SAFE-REMOVE**.
>
> **Rules of evidence — these are what make the audit worth reading:**
>
> - Every finding cites a file path and line number that was actually opened, with the offending
>   code quoted verbatim.
> - "Missing" is only claimed after 3+ failed searches under different names, and the searches
>   are listed.
> - A prior audit ran 2026-07-27 (`docs/SYSTEM-AUDIT-2026-07-27.md`) and repaired many defects,
>   and there is uncommitted WIP in `git diff`. Check both before claiming anything is broken.
>   Stale findings are worse than no findings.
> - Every finding gets adversarially re-verified by a second pass whose job is to *kill* it:
>   re-open the file, hunt for the guard the first pass missed, run any existing test that
>   asserts the correct behaviour. Report survivors as CONFIRMED (exact trigger stated) or
>   PLAUSIBLE. Report the kills too.
> - The Docker stack is running — verify hypotheses with
>   `docker compose exec -T api php artisan test --filter=X` rather than reasoning in the air.
>
> **Deliverables:**
>
> - `docs/SYSTEM-AUDIT-<date>.md` — findings ranked P0→P3 by real-world damage, each with
>   category, module, location, evidence, scenario, impact, and a minimal fix naming real
>   files and functions.
> - A **stuck-process map** table: Module | Record | Stuck status | How it enters | Why no exit | Fix.
> - A **dead-feature table**: Feature | Files | Depended on by | SAFE-REMOVE / REMOVE-WITH-MIGRATION / KEEP.
> - The SAFE-REMOVE deletions actually applied, full suite green afterward.
> - A coverage section stating what was *not* audited.
>
> Merge duplicate root causes into one finding. Rank by damage, not by novelty. No filler.

---

## What changed and why

| Your original | Rewritten | Why |
|---|---|---|
| "audit this whole project for X, Y, Z, etc." | Six named buckets with priority order | "etc." makes an auditor optimize for volume. Named buckets make it optimize for coverage. |
| no evidence bar | file:line + verbatim quote required | Without this you get confident prose about code that does not exist. |
| no staleness guard | prior-audit + `git diff` check mandated | You already fixed a lot on 2026-07-27. Re-reporting it wastes your time twice. |
| no missing-proof bar | 3+ failed searches, listed | Most "missing" features in this repo exist under another name. |
| no verification | adversarial second pass, tests run | One-pass audits are ~half wrong. A skeptic pass that reports its kills is the single biggest quality lever. |
| "remove those safe to remove" | blast radius + verdict, *then* remove | Removal without a dependency trace is how you break a chain silently. |
| no output contract | report + two tables + applied deletions + coverage | Makes the result reviewable and diffable instead of a chat message. |
| implicit scope creep | NOT BUILDING list is out of scope | Otherwise the audit "finds" every feature you deliberately cut. |
| — | rank by real-world damage | Sorts P0 to the top instead of whatever was most interesting to write about. |
| — | state what was NOT audited | An audit that hides its blind spots is worse than one that names them. |

## Reuse

Swap the six buckets for a subset to run a focused pass — e.g. security-only before defense,
or stuck-processes-only after a schema change. The evidence rules and the adversarial verify
pass stay constant; they are what make the output trustworthy regardless of scope.
