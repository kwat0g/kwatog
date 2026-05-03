# Sprint 8 — Defense Checklist & Rehearsal Plan

> Companion to [`plans/sprint-8-polish-dss-and-defense-tasks-69-85.md`](sprint-8-polish-dss-and-defense-tasks-69-85.md).

## T-72 hours

- [ ] Sprint 8 PR merged to `main`
- [ ] CI green on `main` (PHPUnit, SPA build)
- [ ] `pg_dump` of production DB stored in three places (laptop · USB · cloud)
- [ ] HTTPS reachable on prod URL with HSTS, X-Frame-Options, CSP headers
- [ ] Run `php artisan migrate --force` then `db:seed --class=Sprint8DemoSeeder` on prod
- [ ] Verify `make perf-baseline` p95 < 250ms on `/dashboards/plant-manager`

## T-48 hours

- [ ] Record three demo screencasts (1080p), saved under `docs/demo-videos/`
      (gitignored — link list below). Cursor-highlighting on, mic muted.
  - [ ] **Order-to-Cash:** create SO → MRP plan → confirm WO → record output
        → outgoing QC → delivery → invoice → collection
  - [ ] **Procure-to-Pay:** auto-PR from low stock → approve → PO → GRN →
        incoming QC → bill → payment → 3-way match
  - [ ] **Hire-to-Retire:** onboard employee → import DTR → approve leave →
        compute payroll → finalize → bank file → initiate separation →
        clearance → final pay
- [ ] Spare laptop tested with the same dataset, projector cable confirmed
- [ ] Wi-Fi failover plan: MiFi or phone hotspot stress-tested

## T-24 hours

- [ ] Five live dry-runs at the projector resolution (1280×720 typical)
- [ ] Time each demo (target ≤ 6 minutes each)
- [ ] Reset demo data: `make fresh && make seed` then `db:seed --class=DemoDataSeeder` and
      `db:seed --class=Sprint8DemoSeeder`
- [ ] Print spare copies of key screens (in case of total network failure)
- [ ] Charge laptop, spare laptop, phone, presenter remote
- [ ] Slide deck final read-through

## Demo day

- [ ] 30-min buffer arrival
- [ ] Bring: laptop, charger, HDMI/USB-C dongle, presenter remote, spare laptop,
      printed Q&A bank, water
- [ ] Open browser to login screen before audience enters
- [ ] Pre-recorded video fallback ready for each demo if anything crashes

---

## 12-slide presentation outline (≈25 minutes)

1. **Title** — thesis title, your name, panel members, date
2. **Problem** — Ogami's manual workflows, traceability gaps, IATF 16949 audit pain
3. **Three chains** — Order-to-Cash · Procure-to-Pay · Hire-to-Retire
4. **IATF 16949 quality** — woven into chains at four touchpoints, not a separate module
5. **Architecture** — Laravel 11 API + React 18 SPA, Sanctum cookies, modular monolith,
   PostgreSQL + Redis + Meilisearch + Reverb. One slide diagram.
6. **Demo: Order-to-Cash** — screencast (≤ 6 min)
7. **Demo: Procure-to-Pay** — screencast (≤ 6 min)
8. **Demo: Hire-to-Retire** — screencast (≤ 6 min)
9. **Security model** — HTTP-only Sanctum cookies, HashIDs, RBAC layers, encrypted PII,
   2FA-ready scaffolding, audit log
10. **Decision support** — five role-targeted dashboards, real-time WebSocket
    feeds, defect Pareto, OEE, AR/AP aging, chain stage breakdown
11. **Limitations & future work** — explicit NOT-BUILDING list (cost accounting,
    budgets, mobile native apps, etc.) and a roadmap suggestion
12. **Q&A**

---

## Likely panel questions — short answers

| Q                                          | A |
|---                                         |---|
| Why a modular monolith instead of microservices? | Single team, one deploy, one DB. Boundaries enforced via module folders + permission slugs. Decompose later if scale demands. |
| Why Sanctum cookie auth, not JWT?          | Browser app; cookies are HTTP-only so JS can't read them — immune to XSS token theft. CSRF handled via XSRF token + same-origin policy. |
| How do you prevent integer ID enumeration? | HashIDs on every model + Resource. URLs and API responses never expose raw `id`. |
| What if Postgres goes down?                | Daily `pg_dump` to off-machine storage; demo includes a recovery fixture. PgBackRest is on the production roadmap. |
| Why Meilisearch + Postgres + Redis?        | Each plays a different role: Postgres = source of truth, Redis = sessions + dashboard cache, Meilisearch = full-text search across modules. |
| How do you guarantee balanced JEs?         | `JournalEntryService::create` throws `UnbalancedJournalEntryException` if debit ≠ credit before insert; tests cover the path. |
| How is IATF 16949 traceability enforced?   | Every Sales Order edge has a ChainHeader + LinkedRecords panel; every shipment has an auto-generated CoC; every NCR persists root cause + corrective action with audit history. |
| What's tested?                             | PHPUnit suites in `api/tests/` cover services, value objects, and HTTP endpoints. SPA has Vitest unit tests on hooks/components. CI runs both on every PR. |
| Localization roadmap?                      | Strings extracted to `lang/`. Tagalog and Japanese (Ogami HQ language) are first priority. |
| What's the licensing model for thesis use? | All-rights-reserved code. Open-source dependencies (Laravel, React, etc.) carry their own MIT/BSD licenses; documented in the appendix. |

---

## After the defense

- [ ] Final source bundle uploaded to thesis repository (zip + README.md)
- [ ] Production VPS handed over to Ogami IT (root SSH key rotated)
- [ ] Access credentials in shared 1Password vault (panel + advisor + Ogami IT)
- [ ] Tag `v1.0.0-defense` on `main`
