# Ogami ERP

Production-grade ERP for **Philippine Ogami Corporation** — a Japanese-owned plastic injection molding manufacturer. IATF 16949 certified. Modular monolith on Laravel 11 + React 18.

> Read [`CLAUDE.md`](CLAUDE.md) for the project mission, architecture, and rules.
> Read [`docs/PATTERNS.md`](docs/PATTERNS.md) for copy-paste code templates.
> Read [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md) for UI tokens and component specs.
> Task list: [`docs/TASKS.md`](docs/TASKS.md). Database schemas: [`docs/SCHEMA.md`](docs/SCHEMA.md).

## Stack

- **API:** Laravel 11 · PHP 8.3 · PostgreSQL 16 · Redis 7 · Meilisearch · Reverb (WebSocket)
- **SPA:** React 18 · TypeScript · Vite · Tailwind · TanStack Query/Table · Zustand · React Hook Form + Zod
- **Auth:** Sanctum SPA mode with HTTP-only cookies (NEVER bearer tokens)
- **IDs:** HashIDs in URLs and API responses (NEVER raw integer ids)

## Quick start (Docker)

```bash
cp .env.example .env
cp .env.example api/.env
make build
make up
docker compose exec api composer install
docker compose exec api php artisan key:generate
make fresh         # migrate + seed
```

Open http://localhost — SPA served via Nginx; API proxied at `/api/v1/*`; Mailpit UI at http://localhost:8025.

## Make targets

See `make help` for the full list. Common ones:

```bash
make up           # boot all services
make logs         # tail logs
make shell        # bash inside the api container
make migrate      # run pending migrations
make fresh        # drop, migrate, seed
make test         # phpunit + vitest
```

## Project structure

- [`api/`](api) — Laravel 11 modular monolith (`app/Modules/<Module>/...`)
- [`spa/`](spa) — Vite + React 18 + TypeScript SPA
- [`docker/`](docker) — Container build files
- [`docs/`](docs) — Project documentation
- [`plans/`](plans) — Sprint plans

## Sprints

The build is split into 8 sprints (~85 tasks). Sprint 1 (foundation) is documented in [`plans/ogami-erp-sprint-1-foundation-tasks-1-12.md`](plans/ogami-erp-sprint-1-foundation-tasks-1-12.md).

## Security non-negotiables

- HTTP-only Sanctum SPA cookies. **No bearer tokens. No localStorage for auth.**
- Every API Resource emits `hash_id` (string), never raw integer `id`.
- Every financial / state-changing operation wrapped in `DB::transaction()`.
- Every route guarded by `AuthGuard` + `ModuleGuard` + `PermissionGuard` on the frontend, by `auth:sanctum` + `feature:*` + `permission:*` middleware on the backend.
- Sensitive fields (SSS, PhilHealth, TIN, bank account) use Laravel's `encrypted` cast.
