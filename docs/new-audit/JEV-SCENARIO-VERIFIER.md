# Jev Scenario Verifier

## Re-audit 2026-09-19

The verifier was used after deterministic evidence collection for the current full re-audit.
The live run processed 19 scenarios sequentially with `jev-1.13.0` and returned `review` for all
19, with zero service errors. This corroborates uncertainty/coverage gaps; it did not close any
finding and does not replace source, test, permission, financial, quality, or runtime proof.

The Jev verifier is a read-only audit corroboration tool. It does not read the
repository, run tests, change records, close findings, or replace deterministic
authorization and financial checks.

## Workflow

1. Build a scenario matrix for one module or process.
2. Collect current implementation, test, and runtime evidence.
3. Redact secrets, credentials, employee identifiers, customer data, and raw IDs.
4. Put the evidence in the manifest format shown in `JEV-SCENARIO-MANIFEST.example.json`.
5. Run the verifier with `--live`.
6. Treat `gap` and `review` results as findings for agent or human review.
7. Confirm consequential findings with code, tests, or a controlled runtime check.

The scenario categories should include the happy path, invalid input, boundary
values, duplicate/retry, partial completion, concurrency, authorization, failure
recovery, cross-module handoff, and stale-state cases.

## Running It

Keep the manifest on the host and pipe it through standard input. This avoids
mounting repository documents into the API container:

```bash
<manifest.json docker compose exec -T api php artisan \
  audit:verify-scenarios --stdin --live --json
```

Live calls are deliberately opt-in. Normal PHPUnit and CI runs use HTTP fakes and
never spend API credits or depend on TypeSafe availability.

## Interpretation

The command asks Jev five bounded questions: reachability, invariant support,
evidence freshness, coverage status, and risk. It sends one scenario at a time and
pins `jev-1.13.0` by default for reproducible audit output.

The application combines the results in code:

- `covered`: current evidence supports the invariant and coverage is adequate.
- `gap`: the scenario is reachable, current evidence does not support the invariant, and coverage is partial or missing.
- `review`: evidence is ambiguous, stale, contradictory, or below the confidence gate.
- `not_applicable`: Jev judges that the scenario is not reachable, subject to the normal evidence review.
- `service_error`: the external call failed; this never passes silently.

Jev probabilities are corroboration, not proof. A passing regression test or a
deterministic application rule outranks a model judgment. Financial, security,
payroll, quality, and safety findings never auto-close from Jev alone.

## Configuration

Set these in `api/.env` only:

```env
TYPESAFE_API_KEY=
TYPESAFE_ENDPOINT=https://api.typesafe.ai/v1/systemone
TYPESAFE_MODEL=jev-1.13.0
TYPESAFE_AUDIT_MIN_CONFIDENCE=0.75
```

Never use a `VITE_*` variable for the key. Never pass the key as a command-line
argument or include it in a manifest.
