---
name: security-review
description: Use when writing or reviewing any code that handles user input, auth, file upload, money, or PII. Codifies the Laravel + React threat-model checklist specific to kwatog (Sanctum auth, RBAC, Filipino TIN/peso data, file imports).
---

# Security Review Checklist (kwatog)

## When this skill applies

ANY of:

- New endpoint that accepts user input
- New form or page that submits to the API
- File upload, file import (Excel via maatwebsite/excel), or PDF generation
- Anything touching `User`, `Role`, `Permission`, sessions, auth tokens
- Anything touching money: invoices, payments, payroll
- Anything touching PII: TIN, employee names, addresses, phone, email
- Changes to middleware, exception handler, or `app/Http/Kernel.php` equivalents

## API checklist

### Authentication and authorization

- [ ] Route is in a `Route::middleware(['auth:sanctum', ...])->group(...)` block. Public routes are explicit and minimal.
- [ ] Route has the right `permission:<module>.<resource>.<action>` middleware. See [`rbac-and-permissions.md`](rbac-and-permissions.md).
- [ ] FormRequest `authorize()` repeats the permission check. Defense in depth.
- [ ] Tested: a user without the permission returns 403 (Feature test asserts this).
- [ ] Tested: an unauthenticated request returns 401.

### Input validation

- [ ] Every input field is in the FormRequest `rules()`. Unknown fields are rejected (Laravel ignores unknowns by default - acceptable).
- [ ] String length limits set (`max:200` etc.) - prevents DB overflow + DoS.
- [ ] Numeric inputs use `decimal:0,2` (money), `integer`, `min`/`max`. Never accept arbitrary strings as numbers.
- [ ] Regex validation on codes (part numbers, TINs, employee numbers): `regex:/^[A-Z0-9-]{2,30}$/`.
- [ ] Enum-like fields use `Rule::in([...])` or an `Enum::class` rule.
- [ ] File upload uses `file|mimes:...|max:<KB>`. Never trust the client extension. Validate with `mimes:` (server-side) or `mimetypes:`.
- [ ] No `regex:.../i` with user-controlled patterns (catastrophic backtracking risk).

### Mass assignment

- [ ] Model `$fillable` is set explicitly. Never use `$guarded = []` for user-write models.
- [ ] No `Model::create($request->all())`. Use `$request->validated()` so only declared rules are saved.
- [ ] Sensitive fields (`password_hash`, `is_admin`, `tenant_id`) are NOT in `$fillable`. Set them in code paths that explicitly authorize the change.

### SQL injection

- [ ] Eloquent and Query Builder bindings only. `whereRaw(...)` never interpolates user input - always use `?` placeholders or named bindings.
- [ ] `selectRaw(...)` is OK for fixed expressions but never includes user input.
- [ ] `orderBy($column)` with a user-supplied `$column` must whitelist via `Rule::in(['name','created_at',...])`.

### Output

- [ ] API Resource defines exactly which model fields ship to the client. Never `return $model;` directly.
- [ ] Sensitive fields hidden via `$hidden = ['password_hash', 'remember_token']` on the User model and equivalents.
- [ ] Soft-deleted rows excluded from list responses unless the consumer explicitly requested trash and has `<resource>.view_trashed` permission.

### Money

- [ ] Decimal columns (`decimal(18,2)`), never float.
- [ ] No currency conversions client-side. Convert on the server using a stored rate.
- [ ] Totals are recomputed server-side on save; never trust the client-computed total.
- [ ] Audit log entries written for any money-state-change (use existing `audit_logs` table; pattern is in [`docs/PATTERNS.md`](../../../docs/PATTERNS.md)).

### Files

- [ ] Uploaded files stored under `storage/app/<scope>/...`, served via signed URLs or controller streams (not via `public/` symlink for sensitive files).
- [ ] PDF/Excel generation: validate input ranges (a request that asks for "year 0001" can chew memory).
- [ ] Excel imports use `WithChunkReading` and validate per-row before persisting. Never `->all()` an unbounded import.

### Rate limiting

- [ ] Public/auth endpoints have throttle middleware. Defaults: `throttle:api` for authenticated, lower for login.

### Sessions and tokens

- [ ] Sanctum tokens scoped (abilities) when issuing for service accounts.
- [ ] Logout invalidates the current token; for browser-session paths, regenerates session.

## SPA checklist

- [ ] No secrets in client code. `import.meta.env.VITE_*` is OK; anything else doesn't ship.
- [ ] User-rendered HTML goes through React (auto-escapes). Never `dangerouslySetInnerHTML` with user content; if you must, sanitize with DOMPurify.
- [ ] Permission gate (`usePermission`) hides buttons that would 403, but never relied on for security.
- [ ] No tokens in `localStorage` for highly sensitive flows; httpOnly cookies are preferred. (Sanctum SPA auth uses cookie-based sessions.)
- [ ] Never log full requests or PII to the console in production. The `errorLogStore` should redact sensitive fields.
- [ ] CSRF: Sanctum SPA mode handles CSRF cookies automatically; ensure axios is configured for it (`withCredentials: true`).

## PII (Philippine context)

- TIN, SSS, PhilHealth, PAG-IBIG numbers are PII. Mask in lists (`123-***-***`), full only on the detail page for users with view permission.
- Employee birthdates: PII. Same treatment.
- Customer addresses and phone: PII. Filter logs for these.

## Audit log

Any state-changing endpoint that involves money, employees, or roles SHOULD write an audit log row. The table is `audit_logs` and the pattern follows existing modules (see [`api/app/Modules/Admin/`](../../../api/app/Modules/Admin/) audit-log code).

## Verification

- Open the new endpoint in a browser/postman as an unauthenticated user -> 401 expected.
- Open as a user without the permission -> 403 expected.
- Submit invalid payload -> 422 with clear errors.
- Submit valid payload -> 201/200 with the expected Resource shape.
- `git diff` for `.env`, `.env.example`, `config/app.php`. No new secret leaked.
- Search for `dd(`, `dump(`, `console.log(` in your diff. Remove them.

```bash
grep -rE 'dd\(|dump\(|console\.(log|info|debug)\(' \
  --include='*.php' --include='*.ts' --include='*.tsx' \
  $(git diff --name-only main...HEAD)
```

Then run [`code-quality-gate.md`](code-quality-gate.md).

## Out-of-scope (explicitly)

- Penetration testing, fuzzing, formal threat modeling. This skill is a checklist for routine changes, not a security audit.
- WAF / network-layer concerns. See ops.

If something feels off and the checklist does not cover it, **stop and surface it** rather than ship.
