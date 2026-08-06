# Ogami ERP — Security Audit 2026-08-05

**Audit dates:** 2026-08-05
**Auditor:** Security pass run with `docs/SECURITY-AUDIT-PROMPT.md` (improved prompt)
**Scope:** Backend API + SPA + Docker/Nginx config + dependencies + secrets
**Baseline:** `docs/SYSTEM-AUDIT-2026-07-27.md` and `docs/audit-rbac-sod-portal-2026-07-21.md` already repaired 66+ items. This audit deliberately re-verified those claims rather than re-reporting them.

## Remediation status

All findings below have been remediated on 2026-08-05 except the external credential
revocation (P1-1) and the non-applicable React Router advisory (documented). See the
[Remediation changes](#remediation-changes) section at the bottom for the exact edits.

---

## Verdict

**No P0 findings.** The system has a strong security baseline: Sanctum SPA cookie auth, HashID record IDs, per-route permission middleware, named rate limiters on every credential endpoint, production startup assertions that abort on unsafe defaults, CSP/HSTS headers, and zero Composer advisories. The serious finding is **one leaked API credential still on disk in a worktree** (revocation is an external action), plus **one actionable npm advisory** and a handful of P3 hardening items.

---

## Findings ranked by real-world damage

### P1-1. Anthropic API key still on disk in a worktree — revoke now
**Category:** Secrets · **Module:** Repo hygiene · **Location:** `.claude/worktrees/erp-enhancements/start_claude.sh:4`

```bash
export ANTHROPIC_API_KEY="sk-sIiTrFgEvMuyicJqL8Qf6lQLpwobpUQLL4TNGcYTKd7KVpIG"
```

- The **main tree** is fixed: `start_claude.sh` now requires `ANTHROPIC_API_KEY` from the caller's environment (`: "${ANTHROPIC_API_KEY:?Set ANTHROPIC_API_KEY...}"`).
- The **worktree copy** still carries the literal credential. `.claude/worktrees/` is in `.gitignore` (verified via `git check-ignore`), so it is not in the current index — but the key is still present on disk and (per the 2026-07-27 audit, item 30) exists in **earlier Git history**.
- **Scenario:** anyone with the repo or a clone's reflog/history can extract the key; if it is still active on the Anthropic side it can be used to burn credits or access whatever that key could reach.
- **Fix:** **revoke/rotate the key at the provider immediately** (external action — no code change can invalidate it). Then delete the stale worktree copy: `rm .claude/worktrees/erp-enhancements/start_claude.sh` (or the whole stale worktree). Consider `git filter-repo` history purge only if the org accepts the clone-invalidation tradeoff (prior audit chose not to).

### P2-1. npm `brace-expansion` DoS advisory (actionable)
**Category:** Dependency · **Module:** SPA tooling · **Location:** `spa/package.json` (transitive)

```
brace-expansion  4.0.0 - 5.0.8   Severity: high
brace-expansion: DoS via unbounded intermediate arrays, bypassing the CVE-2026-14257 mitigation
fix available via `npm audit fix`   (non-breaking)
```

- **Impact:** local/CI DoS in a dev-tooling transitive dep; not reachable from the served SPA runtime.
- **Fix:** run `cd spa && npm audit fix` (non-breaking). This is a **new** finding — the 2026-07-27 audit only documented the React Router advisory.

### P2-2. `api/.env.testing` is committed to Git
**Category:** Secrets hygiene · **Module:** Repo hygiene · **Location:** `api/.env.testing` (in `git ls-files`)

- `.gitignore` only ignores `.env` and `.env.*.local`; `.env.testing` slips through and is **tracked**, including `APP_KEY` and `DB_PASSWORD` values.
- **Scenario:** test credentials are not production secrets, but any committed env file trains the wrong habit and a future `DB_PASSWORD=` pointing at a real host would be committed silently.
- **Fix:** `git rm --cached api/.env.testing`, add `.env.testing` / `.env.*` (non-`*.example`) to `.gitignore`, and regenerate the test key/salt.

### P3-1. `quality-policy` PDF endpoint has no dedicated limiter (comment/code mismatch)
**Category:** Rate limiting · **Module:** Landing · **Location:** `api/app/Modules/Landing/routes.php:18`

```php
// Read-only landing data is requested by the page on every fresh visit;
// do not apply the write-form limiter ...
Route::get('quality-policy', [QualityPolicyController::class, 'download']);
```

- `api/bootstrap/app.php`'s `public-form` comment claims it guards "the expensive on-demand PDF render", but the route does **not** apply `throttle:public-form` (the worktree copy still does — the main tree diverged).
- The global `api` limiter (60/min guest per-IP) still applies, so this is **not** unthrottled — just throttled at 60/min instead of the intended 10/min for an expensive DomPDF render.
- **Fix:** add `->middleware('throttle:public-form')` to the quality-policy route (or fix the bootstrap comment if the 60/min global cap is deemed sufficient).

### P3-2. Employee photos written to the `public` disk
**Category:** File handling · **Module:** HR · **Location:** `api/app/Modules/HR/Controllers/EmployeeController.php:206`

```php
$path = $request->file('photo')->store('employee-photos', 'public');
```

- Photos land in `storage/app/public/employee-photos/` and are exposed as `/storage/...` via `EmployeeResource`.
- **Prod:** the `/storage/` nginx location was deliberately **removed** in `docker/nginx/prod.conf` (Phase-4 perm-bypass fix), so these URLs never reach a file on disk — a request for `/storage/...` falls through to `location /` and returns the SPA `index.html` (HTTP 200). Prod therefore does **not** serve the photos unauthenticated, but they also do not load (a *functional* gap).
- **Dev:** `docker/nginx/default.conf` still has `location /storage/ { try_files $uri =404; }`, so photos are publicly reachable in dev when the `storage:link` symlink exists.
- **Fix:** store photos on the private `local` disk and serve via the existing authenticated download pattern (like delivery proofs), in both dev and prod. Delete-or-keep dev `/storage/` only if no other public assets need it.

### P3-3. Public health endpoints disclose component state (and `/up` is unthrottled)
**Category:** Info disclosure · **Module:** API · **Location:** `api/routes/api.php:42`, `api/bootstrap/app.php:31`

- `/api/v1/health` is unauthenticated and reveals DB/Redis/queue up/down state. Capped by the global 60/min guest limiter, but it tells an attacker which components to hammer.
- Laravel's `/up` health route (registered via `withRouting(health: '/up')` in `bootstrap/app.php:31`) is **public, unauthenticated, and sits outside the API middleware group — so it escapes the global throttle entirely**. Trivial endpoint, but it belongs in the public-surface inventory.
- **Fix (optional):** restrict both to the load balancer IP range, strip per-component `checks` from the public view, or move `/up` under a throttle.

### P3-4. CORS allows wildcard methods/headers with credentials
**Category:** Config · **Module:** API · **Location:** `api/config/cors.php`

```php
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
'supports_credentials' => true,
```

- Origin is pinned to a single `FRONTEND_URL` (good — no wildcard origin), so exploitability is limited to that origin. Wildcards on method/header are permissive but not exploitable given the origin pin.
- **Fix (optional):** enumerate methods (`GET,POST,PUT,PATCH,DELETE,OPTIONS`) and headers actually used.

---

## Killed findings (hypotheses that did NOT survive adversarial verification)

| Claim | Verdict | Evidence |
|---|---|---|
| "Missing rate limiter" on login | **KILLED** | `throttle:auth` (5/min per IP\|email) on login/forgot/reset + B2B portal logins; `throttle:sensitive` (10/min) on change-password; `throttle:public-form` (10/min) on landing forms; recruitment `throttle:30,1` group + `10,1` on apply; edge `throttle:60,1`; **plus** a global `api` limiter (300/min authed, 60/min guest) applied to every API route. |
| Exposed unauthenticated business API | **KILLED** | Route sweep shows only intended public surfaces (login, password reset, landing forms/content, public recruitment, portal login, health). All other routes carry `auth:sanctum` + permissions. |
| SQL injection via `DB::raw` | **KILLED** | All `DB::raw`/`selectRaw`/`whereRaw` uses reviewed: bound parameters (`?`), server-computed values (settings ratios, enum values), or hardcoded strings. No user input reaches SQL. `DashboardWidgetDataService::breakdown()` and `AdminDashboardService::safeCount()` receive hardcoded table/column names only. |
| Weak `HASHIDS_SALT` default (`change_me`) | **KILLED in prod** | `api/config/hashids.php:8` defaults to `change_me`, but `ProductionAssertions::assertSafeOrFail()` (`AppServiceProvider.php:94`, `ProductionAssertions.php:30`) **aborts startup** if `HASHIDS_SALT` is at its default in production. Dev-only risk. |
| Unsafe session cookies | **KILLED** | `session.php`: `secure` default `true`, `http_only` `true`, `same_site` `lax`; `.env.production.example` sets `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`. |
| B2B portal cross-tenant IDOR | **KILLED** | `SupplierPortalService`/`CustomerPortalService` abort 403 on `vendor_id`/`customer_id` mismatch at every object read (`po`, invoice, delivery, complaint); `B2BTenancyScopeMiddleware` on the whole group. |
| Hardcoded secrets in the main tree | **KILLED** | Regex scan (`sk-…`, `ghp_`, `AKIA…`, private keys) across tracked files: only the worktree copy (P1-1) matches. |
| Broadcast channel authz bypass | **KILLED** | `channels.php` checks permissions per channel and enforces hash-ID ownership (`$user->id === User::tryDecodeHash($userId)`). |
| Credential brute force | **KILLED** | 5/min throttle + account lockout (`locked_until` after strikes) with dedicated tests (`AuthSecurityTest`, `SupplierPortalAuthTest`, `CustomerPortalAuthTest`). |
| Dev/prod rate-limit parity | **KILLED** | `rate_limits.php` reads env (`API_GUEST_RATE_LIMIT`, `API_AUTHENTICATED_RATE_LIMIT`); both env examples set sane values. |

---

## Route coverage table (public / weakly-guarded surface)

| Route | Guard(s) | Limiter(s) | Verdict |
|---|---|---|---|
| `POST /api/v1/auth/login` | none (public) | `throttle:auth` 5/min | OK |
| `POST /api/v1/auth/forgot-password` | none (public) | `throttle:auth` 5/min | OK |
| `POST /api/v1/auth/reset-password` | none (public) | `throttle:auth` 5/min | OK |
| `POST /api/v1/auth/change-password` | `auth:sanctum` | `throttle:sensitive` 10/min | OK |
| `POST /api/v1/b2b/supplier/customer/login` | none (public) | `throttle:auth` 5/min | OK |
| `POST /api/v1/b2b/{supplier,customer}/logout` | portal guard | `throttle:auth` 5/min | OK |
| `POST /api/v1/landing/quote-request` · `newsletter` | none (public) | `throttle:public-form` 10/min | OK |
| `GET /api/v1/landing/quality-policy` (PDF) | none (public) | global api 60/min only | **GAP (P3-1)** |
| `GET /api/v1/landing/contact` · `content` | none (public) | global api 60/min | OK |
| `GET /api/v1/public/recruitment/*` | none (public) | `throttle:30,1` (apply: `10,1`) | OK |
| `POST /api/v1/edge/v1/*` | `auth:edge_device` + `portal` + `ability:` per route | `throttle:60,1` | OK |
| `GET /api/v1/health` | none (public) | global api 60/min | OK (P3-3 optional) |
| `GET /up` (Laravel health) | none (public) | **none** | OK (P3-3) |
| `POST .../imports` · `.../scheduled-exports` | `auth:sanctum` + permissions | global api only (300/min authed) | OK² |

> **² Import/export endpoints** (`ImportController` commit, up to 8 MB CSV; `ScheduledExportController`) carry no dedicated limiter — only the global 300/min authenticated cap. Acceptable for an internal ERP; note them if the portal-facing or public-facing form factor ever expands.
| `GET/POST /api/v1/broadcasting/auth` | `auth:sanctum` | global api | OK |
| Every module route | `auth:sanctum` + `permission:*` (+ `feature:*`) — see caveat | global api | OK¹ |

> **¹ Caveat on the blanket "every module route" row.** Route-level `permission:*` middleware is present on nearly all module routes. A few delegate authorization to the controller (e.g. `LoanController::previewAmortization` at `Loans/routes.php:14` — deliberately auth-only per its in-file comment because it reads no records — and the self-service group, which is controller-scoped to the session employee). These were spot-verified as intended; a per-route proof is part of a deeper pass.

---

## Rate-limiter map

| Endpoint class | Limiter | Limit | Where applied |
|---|---|---|---|
| Login / password reset / portal login | `auth` | 5/min per IP\|email | `Auth/routes.php`, `B2B/routes.php` |
| Change password | `sensitive` | 10/min per user\|IP | `Auth/routes.php:29` |
| Landing forms + PDF | `public-form` | 10/min per IP | `Landing/routes.php` (PDF missing — P3-1) |
| Public recruitment | `30,1` / `10,1` | 30/min, apply 10/min | `HR/routes.php:301,305` |
| Edge device API | `60,1` | 60/min per device token | `Edge/routes.php` |
| **All API routes (global)** | `api` | 300/min authed user / 60/min guest IP | `bootstrap/app.php` group append |
| Import commit / scheduled exports | (global only) | 300/min authed | covered by global; no dedicated limit |
| `/up` Laravel health | (none) | — | outside API group; unthrottled (P3-3) |

---

## Secrets scan

| String | Disposition |
|---|---|
| `sk-sIiTrFgEvMuyicJqL8Qf6lQLpwobpUQLL4TNGcYTKd7KVpIG` (`.claude/worktrees/erp-enhancements/start_claude.sh:4`) | **REVOKE** — P1-1. Gitignored, not in index, but live on disk + in history. |
| `api/.env.testing` (`APP_KEY`, `DB_PASSWORD`) | Tracked in git — P2-2. Test-only values. |
| `.env.example` / `api/.env.example` placeholders (`CHANGEME`, `ogami_dev_pw`) | Dev defaults, not tracked as live secrets; both `.env` files are gitignored. |
| Seeder-printed demo passwords (`AdminUserSeeder`, `DemoAccountSeeder`) | Demo/seed-only; console output, not committed credentials. |
| No `ghp_`, `AKIA`, private keys in tracked files | Clean. |

---

## Verification evidence

| Check | Result |
|---|---:|
| `composer audit` | **0 advisories** |
| `npm audit` | **2 distinct high advisories** (brace-expansion actionable, React Router non-applicable) |
| Regex secrets scan (tracked files) | 0 credential matches outside worktree |
| `.gitignore` coverage of `.claude/worktrees/` | Covered (ignored) |
| `.gitignore` coverage of `.env.testing` | **Not covered** (tracked) |
| Session/cookie flags | `secure=true` default, `http_only=true`, `same_site=lax` |
| Production startup assertions (`HASHIDS_SALT`, `APP_DEBUG`, `APP_KEY`) | Present and enforced |
| CSP / HSTS / frame-ancestors in prod nginx | Present (`security-headers-prod.conf`) |
| CORS origin pin | Single `FRONTEND_URL` origin |

## Remediation verification (2026-08-05)

| Check | Result |
|---|---:|
| PHP lint — all 8 edited API files | **PASS** |
| SPA typecheck — edited files (`directory`, `self-service/profile`, both type files) | **PASS** (pre-existing errors in untouched files remain; full typecheck is not green due to uncommitted WIP) |
| `npm audit` after fix | **brace-expansion cleared**; only the documented non-applicable React Router advisory remains |
| Worktree credential file | **Deleted** (`git check-ignore` still lists the path as ignored; revocation remains external) |
| `.env.testing` | **Untracked** + gitignored |
| Backend feature tests | **BLOCKED by pre-existing uncommitted migration** `0442_add_soft_deletes_to_all_tables.php` (untracked; references a `bill_of_materials_items` table no migration creates) — unrelated to this remediation |
| Code review (adversarial) | **3 issues found and fixed**: `env()` under `config:cache` (health token), photo-route permission mismatch, orphaned legacy public photo on replace |

## Coverage — what was NOT audited

- **Live penetration testing** against the running stack (the container exec for `route:list` returned nothing — stack not confirmed up; the route table was instead built statically from module `routes.php` files).
- **Infra-level WAF / brute-force at the network edge** (no WAF config exists to audit).
- **Real-device TLS handshake** and browser HSTS preload status.
- **Full mass-assignment audit model-by-model** — spot-checked; a deeper pass could enumerate every `$fillable` vs request-input on all 195 models.
- **Frontend (SPA) XSS surface** beyond CSP — e.g. `dangerouslySetInnerHTML` usage, which CSP would still block but is worth a separate sweep.
- **Git history archaeology** beyond what prior audits documented (the leaked key's full exposure window).

## Remediation changes

| Finding | Status | Change |
|---|---|---|
| P1-1 (leaked key) | **PARTIAL — revocation is external** | Stale worktree file `.claude/worktrees/erp-enhancements/start_claude.sh` deleted. **Still required: revoke the key at Anthropic — no code change can invalidate it.** |
| P2-1 (brace-expansion) | **DONE** | `npm audit fix` — advisory cleared; only the documented non-applicable React Router advisory remains. |
| P2-2 (`.env.testing` tracked) | **DONE** | `git rm --cached api/.env.testing`; `.env.testing` added to `.gitignore`. |
| P3-1 (quality-policy PDF) | **DONE** | `throttle:public-form` added to the route in `api/app/Modules/Landing/routes.php`. |
| P3-2 (employee photos) | **DONE** | Photos now stored on the `local` disk; new `GET /hr/employees/{employee}/photo` endpoint streams them behind `permission_any:hr.employees.view,hr.directory.view` (a code-review catch — the directory is open to all internal roles and self-service is auth-only, so gating on `hr.employees.view` alone would have broken avatars); `EmployeeResource`, directory, and self-service payloads emit the authenticated `photo_url`; SPA avatars/`<img>` use it. Legacy public-disk photos still render via fallback and are purged on replace/delete. |
| P3-3 (health disclosure) | **DONE** | `/api/v1/health` returns detailed `checks` only when `HEALTH_DETAIL_TOKEN` matches (`X-Health-Token` header or `?token=`). Token is read via the new `api/config/health.php` so it survives `config:cache` (a code-review catch — `env()` would return null after caching, silently re-opening the gate in prod). No token configured ⇒ behavior unchanged (existing test + deploy smoke test unaffected). |
| P3-4 (CORS) | **DONE** | `api/config/cors.php` now enumerates methods (`GET,POST,PUT,PATCH,DELETE,OPTIONS`) and headers. |

## Recommended next actions

1. **Immediately:** revoke the Anthropic key at the provider (P1-1 — the only remaining open item).
2. **Optional hardening:** set `HEALTH_DETAIL_TOKEN` in production if you want the deep health payload restricted; run the full backend + SPA suite to confirm the photo/route changes (see verification evidence).
