# Project knowledge — Ogami ERP

Production-grade ERP for Philippine Ogami Corporation (Japanese-owned plastic injection molding manufacturer, IATF 16949). Modular monolith: **Laravel 11 API + React 18 SPA**, Docker Compose. Organized around 3 chains (Order-to-Cash, Procure-to-Pay, Hire-to-Retire) and 12 modules.

> **Read first when coding:** [`CLAUDE.md`](CLAUDE.md) (master rules), [`docs/PATTERNS.md`](docs/PATTERNS.md) (copy-paste templates — mandatory before writing code), [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md), [`docs/SCHEMA.md`](docs/SCHEMA.md), [`docs/TASKS.md`](docs/TASKS.md), [`docs/DEPLOY.md`](docs/DEPLOY.md).

## Quickstart

```bash
cp .env.example .env
cp .env.example api/.env          # two .env files: root (Compose) + api/ (Laravel)
make build && make up
docker compose exec api composer install
docker compose exec api php artisan key:generate
make fresh                         # migrate + seed (DESTRUCTIVE — never on prod)
```

- SPA + API at http://localhost (API proxied at `/api/v1/*`)
- Mailpit UI at http://localhost:8025
- Reverb WebSocket at `ws://localhost:8080` (direct, **not** proxied through Nginx)

## Common commands (`Makefile`)

| Command | Purpose |
|---|---|
| `make up` / `make down` / `make logs` / `make ps` | Service lifecycle |
| `make shell` / `make spa-shell` / `make tinker` | Container shells |
| `make migrate` / `make seed` / `make fresh` | DB ops (`fresh` is destructive) |
| `make test` | PHPUnit + Vitest |
| `make lint` | ESLint + php-cs-fixer (dry-run) |
| `make analyse` | PHPStan + `tsc --noEmit` |
| `make build-spa` / `make deploy` / `make prod-*` | Production (requires `SERVER_NAME`, see `docs/DEPLOY.md`) |

SPA scripts (`spa/package.json`): `npm run dev | build | preview | lint | test | typecheck`.
API tests directly: `docker compose exec api php artisan test`.

## Architecture

```
React 18 SPA (Vite + TS) ──HTTP-only cookies──▶ Laravel 11 REST API (PHP 8.3)
                                                       │
                  PostgreSQL 16 · Redis 7 · Meilisearch · Reverb (WS)
```

Services in `docker-compose.yml`: `api`, `spa`, `nginx`, `db`, `redis`, `meilisearch`, `reverb`, `queue`, `mailpit`.

### Key directories

- `api/app/Modules/<Module>/` — `Controllers/ Models/ Services/ Requests/ Resources/ Jobs/ routes.php`. Modules: `Auth, HR, Attendance, Leave, Payroll, Loans, Accounting, Inventory, Purchasing, SupplyChain, Production, MRP, CRM, Quality, Maintenance, Dashboard`.
- `api/app/Common/` — shared `Traits/` (`HasHashId`, `HasAuditLog`, `HasApprovalWorkflow`), `Services/` (`ApprovalService`, `DocumentSequenceService`, `NotificationService`), `Enums/`, `Middleware/`.
- `api/database/migrations/` — numbered `0001_`, `0002_`, …
- `api/resources/views/pdf/` — DomPDF Blade templates.
- `spa/src/{api,components,hooks,layouts,pages,stores,types,lib,styles}/`
- `docker/`, `docs/`, `plans/` (sprint plans), `scripts/`.

## Conventions (non-negotiables — see `CLAUDE.md` for full list)

### Security
- **Auth:** Sanctum SPA mode + HTTP-only cookies. **Never** Bearer tokens. **Never** `localStorage`/`sessionStorage` for auth. Always `withCredentials: true`; hit `/sanctum/csrf-cookie` before login.
- **IDs:** Every model uses `HasHashId`. API Resources emit `hash_id` (string) — **never** raw integer `id`. SPA types declare `id: string`.
- **Sensitive fields** (SSS, PhilHealth, TIN, bank account) use Laravel `encrypted` cast + masking in Resources.
- **Route guards:** Frontend `AuthGuard` + `ModuleGuard` + `PermissionGuard`. Backend re-enforces with `auth:sanctum` + `feature:*` + `permission:*` middleware (frontend guards are UX only).

### Backend (Laravel)
- `declare(strict_types=1);` everywhere.
- Controllers stay thin → delegate to `Service`. Validation + authz in `FormRequest`. JSON shape in `Resource`. Enums for all status/type fields.
- **Money:** `decimal(15,2)` — never float. JSON returns decimals as strings.
- Every state-changing/financial op wrapped in `DB::transaction()`.
- Migrations numbered (`0001_`, …). Document numbers `XXX-YYYYMM-NNNN` issued via `document_sequences` (monthly reset).
- Never use `DB::raw()` with user input.

### Frontend (React + TS)
- Stack: TanStack Query (data), TanStack Table (tables), Zustand (stores), React Hook Form + Zod (forms), Tailwind + tokens in `spa/src/styles/tokens.css`, `lucide-react`, `recharts`, `laravel-echo` + `pusher-js`.
- Pages lazy-loaded via `React.lazy()`. List pages handle 5 states (loading/error/empty/data/stale).
- Mutations always `toast.success/error` + `queryClient.invalidateQueries`.
- Numbers rendered with `font-mono tabular-nums`. Statuses via `<Chip>` with semantic variants.
- **URL routing:** every URL starts with the umbrella module slug (`/hr`, `/inventory`, `/payroll`, `/admin`, `/supply-chain`, …). No bare resource paths. Sidebar uses longest-prefix match.
- Filesystem layout under `spa/src/pages/` does **not** have to mirror URL paths exactly.

### Process
- Before writing any code, find the matching template in `docs/PATTERNS.md` and copy/adapt it. Don't improvise structure.
- Pattern for a full module: Migration → Enum → Model → Service → Request → Resource → Controller → Routes → Types → API client → Pages → Components.
- Git commits per task: `feat: task N — description`.

## Gotchas

- **Two `.env` files** — root `.env` (Compose vars) and `api/.env` (Laravel). Both seeded from the same `.env.example`. `HASHIDS_SALT`, `APP_KEY`, and `SANCTUM_STATEFUL_DOMAINS` must be set in `api/.env`.
- **Reverb cannot be path-proxied through Nginx.** The browser hits Reverb directly on `REVERB_HOST_PORT` (default 8080). `VITE_REVERB_APP_KEY` **must** equal `REVERB_APP_KEY`.
- **`make fresh` drops the database.** Never run against prod.
- **`make deploy`** requires `export SERVER_NAME=erp.your.domain` and uses `docker-compose.prod.yml`. Full runbook: `docs/DEPLOY.md`.
- **Backend API paths** (`/api/v1/...`) are independent of SPA URL prefixes — don't rename them when restructuring SPA routes.
- **Out-of-scope ("NOT BUILDING")** — see `CLAUDE.md`. Don't build: Finance module, bank rec, dashboard customizer (react-grid-layout), import wizard with mapping, automation rule builder UI, setup wizards, system health dashboard, RFQ, etc.

## Pointers

- Master rules: [`CLAUDE.md`](CLAUDE.md)
- Code templates: [`docs/PATTERNS.md`](docs/PATTERNS.md)
- UI tokens: [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md)
- DB schema: [`docs/SCHEMA.md`](docs/SCHEMA.md) · seeds: [`docs/SEEDS.md`](docs/SEEDS.md)
- Backlog: [`docs/TASKS.md`](docs/TASKS.md), [`docs/NEW-TASKS.md`](docs/NEW-TASKS.md), [`docs/POLISH-TASKS.md`](docs/POLISH-TASKS.md)
- Deploy: [`docs/DEPLOY.md`](docs/DEPLOY.md) · user manual: [`docs/USER-MANUAL.md`](docs/USER-MANUAL.md)
- Sprint plans: [`plans/`](plans/)
