# Ogami ERP — Reusable Security-Audit Prompt

Your original one-liner ("audit for security vulnerabilities, missing rate limiter, exposed
api, weak security, etc."), rewritten so a security pass produces verified, ranked, actionable
findings instead of a list of plausible-sounding alarms. Paste the block below as-is; edit the
bracketed bits.

---

## The prompt

> Security-audit the Ogami ERP monorepo at `/home/kwat0g/Desktop/kwatog`. Read `CLAUDE.md` first
> — it defines the auth model, the security non-negotiables, and the **NOT BUILDING** cut-scope
> list.
>
> **Stack facts (verified, do not re-derive):** Laravel 11 API mounted at `/api/v1`, React 18
> SPA, Sanctum SPA-mode cookie auth (never bearer tokens), HashIDs on every model, PostgreSQL.
> Rate limiters registered in `api/bootstrap/app.php`: `auth` (5/min per IP|email), `api`
> (300/min authenticated per-user, 60/min guest per-IP, applied globally via
> `ThrottleRequests::class.':api'`), `sensitive` (10/min), `public-form` (10/min per-IP). Module
> routes auto-mount from `api/app/Modules/<Module>/routes.php` via `ModuleServiceProvider`.
> Nginx security headers live in `docker/nginx/security-headers-{dev,prod}.conf`. Prior audits
> `docs/SYSTEM-AUDIT-2026-07-27.md` and `docs/audit-rbac-sod-portal-2026-07-21.md` already
> repaired 66+ items — **check both before claiming anything is broken. Stale findings are
> worse than no findings.**
>
> **Scope — audit in these buckets, in this order:**
>
> 1. **Authentication & session security.** Login/logout, forgot/reset password, change
>    password, CSRF priming, session fixation/rotation on login, session idle timeout and
>    password-expiry middleware coverage (which authenticated routes *escape* them), cookie
>    flags (`secure`, `http_only`, `same_site`), the B2B portal guards (`supplier_portal`,
>    `customer_portal`) and their token lifecycle (the SPA persists portal bearer tokens in
>    tab-scoped sessionStorage — audit that surface hard), and edge-device Sanctum token
>    abilities (`ability`/`abilities` middleware). Verify the `throttle:auth`/`throttle:sensitive`
>    limits actually attach to every credential and OTP endpoint.
> 2. **Rate limiting gaps.** Build the real list of *public, unauthenticated* routes (login,
>    forgot/reset password, public recruitment, landing forms, B2B portal login, any
>    `/storage/` or unauthenticated file routes, health endpoints) and confirm each has a
>    named limiter. Then do the same for *state-changing* authenticated routes that allow a
>    cheap DoS or database-write spam (imports, bulk ops, document generation, SMS/email sends,
>    queue triggers). Report any endpoint with no or an obviously wrong limit — but "missing"
>    is only claimed after the global `api` limiter has been accounted for.
> 3. **Exposed API & information disclosure.** Enumerate every route with no `auth:sanctum` or
>    portal guard and prove it belongs public. Look for: sensitive fields leaking via
>    unauthenticated or weakly-scoped responses (payslips, salaries, SSS/TIN/bank, credentials),
>    error messages exposing DB/SQL internals or stack traces, verbose exception envelopes,
>    `/storage/` and uploaded-file URLs that bypass controller permission checks, the `/health`
>    endpoint's information content, `.env`/`.git`/`debug` exposure through nginx or Laravel,
>    and any route that returns raw integer `id`s where the contract requires HashID.
> 4. **Authorization & IDOR.** For every module route group, confirm the permission middleware
>    matches the SPA guard and the actual operation. Hunt for: list-endpoint scoping that
>    detail-endpoints skip (a known past defect class — a guessed HashID reaching an unscoped
>    service), cross-department and cross-tenant access (B2B `B2BTenancyScopeMiddleware` — prove
>    supplier A cannot read supplier B's PO or invoice by HashID), self-service grants leaking
>    back-office data, approval self-approval and bypass, and permission names that exist in
>    code but not in the seed catalog (or vice versa).
> 5. **Injection & input handling.** `DB::raw` with user input, string interpolation into SQL
>    where clauses, order-by / filter injection, mass-assignment (check `$fillable` vs request
>    input on every model touched by `fill()`/`create()`), CSV/Excel formula injection, PDF
>    renderer injection, filename/path traversal on uploads and downloads, SSRF in any URL-fetch
>    feature, and the `SanitizeInput` middleware's actual behavior.
> 6. **Secrets & configuration.** Scan the whole tree (including `spa/`, `scripts/`, `.github/`,
>    `docker/`) for hard-coded credentials, API keys, tokens, and private keys — note they may
>    survive in `.claude/worktrees/` and earlier Git history. Audit `.env.example` and the two
>    real `.env` files for weak defaults (`APP_DEBUG`, `APP_KEY`, `HASHIDS_SALT`,
>    `SANCTUM_STATEFUL_DOMAINS`, admin bootstrap credentials), `.dockerignore`/`.gitignore`
>    coverage, and dependency advisories (`composer audit`, `npm audit` — report every
>    non-applicable finding with the reason it does not apply).
> 7. **File handling.** Upload validation (extension/MIME/size), storage-disk isolation of
>    private files, authenticated download endpoints, deletion compensation, and whether any
>    upload can reach a publicly-served path.
>
> **Rules of evidence — these are what make the audit worth reading:**
>
> - Every finding cites a file path and line number actually opened, with the offending code
>   quoted verbatim.
> - "Missing" (a guard, a limiter, a permission) is only claimed after 3+ failed searches under
>   different names, and the searches are listed.
> - Stale-finding guard: read `docs/SYSTEM-AUDIT-2026-07-27.md` and
>   `docs/audit-rbac-sod-portal-2026-07-21.md` first; do not re-report their repairs. Also check
>   `git diff` for uncommitted WIP that changes the picture.
> - Every finding is adversarially re-verified by a second pass whose job is to *kill* it:
>   re-open the file, hunt for the guard the first pass missed, run the existing test that
>   asserts the correct behaviour. Report survivors as CONFIRMED (exact trigger stated) or
>   PLAUSIBLE. Report the kills too.
> - The Docker stack is running — verify hypotheses with
>   `docker compose exec -T api php artisan test --filter=X` or a curl probe against a live
>   endpoint rather than reasoning in the air. Do not run destructive actions (drops, sends,
>   password resets) against anything but a throwaway test database.
>
> **Deliverables:**
>
> - `docs/SECURITY-AUDIT-<date>.md` — findings ranked P0→P3 by real-world damage, each with
>   category, module, location, evidence (file:line + quote), attack scenario, impact, and a
>   minimal fix naming real files and functions.
> - A **route-coverage table**: Route | Guard(s) | Limiter(s) | Verdict (OK / GAP / EXPOSED).
> - A **rate-limiter map**: Endpoint class | Limiter | Limit | Applied where.
> - A **secrets scan** section listing every credential-ish string found and its disposition.
> - A **verification evidence** table: which tests/probes were run and their results.
> - A coverage section stating what was *not* audited (e.g. infra-level WAF, real-device TLS).
>
> Merge duplicate root causes into one finding. Rank by damage, not by novelty. No filler.
> Do not fix anything — report only.

---

## What changed and why

| Your original | Rewritten | Why |
|---|---|---|
| "security vulnerabilities" (open-ended) | Seven named buckets in priority order, each mapped to this repo's real attack surface | Open-ended scope makes an auditor list generic CVEs instead of hunting this codebase's actual weak spots (scoped-detail IDOR, portal tenancy, limiter gaps). |
| "missing rate limiter" (as a claim) | "Verify the limits attach; account for the global `api` limiter before claiming missing" | The system *has* a global 300/60 limiter plus named ones. Treating "missing" as a premise produces false positives; it must be a conclusion. |
| "exposed api" (as a claim) | "Enumerate unauthenticated routes, prove each is meant public, hunt disclosure surfaces" | "Exposed API" alone is unactionable; the useful output is the route-coverage table. |
| "weak security, etc." | HashID leakage, portal tenancy, session-expiry escapees, secrets in worktrees/history, non-applicable npm findings with reasons | "etc." hides the most dangerous, repo-specific findings. Named cases force real checks. |
| no evidence bar | file:line + verbatim quote required | Without this you get confident prose about code that does not exist. |
| no staleness guard | prior audits + `git diff` check mandated | You fixed a lot on 2026-07-27 and 2026-07-21. Re-reporting it wastes your time twice. |
| no missing-proof bar | 3+ failed searches, listed | Most "missing" guards in this repo exist under another name or middleware alias. |
| no verification | adversarial second pass + live probes/tests run | One-pass audits are ~half wrong. A skeptic pass that reports its kills is the single biggest quality lever. |
| no output contract | report + route-coverage table + limiter map + secrets scan + verification table + coverage | Makes the result reviewable and diffable instead of a chat message. |
| — | "Do not fix anything — report only" | A security audit that edits code while reporting destroys the evidence trail and can't be reviewed as a unit. Fix in a follow-up pass. |

## Reuse

- **Security-only pass before a defense demo** — use all seven buckets, they are the defense gate.
- **Before a public launch** — run buckets 1, 3, 6, 7 at minimum; bucket 2 before enabling
  public-facing forms at scale.
- **After a schema or auth change** — buckets 4 and 5 catch the two highest-churn defect
  classes in this repo (unscoped detail endpoints, permission/route drift).
- The evidence rules, staleness guard, and adversarial verify pass stay constant — they are
  what make the output trustworthy regardless of scope.
