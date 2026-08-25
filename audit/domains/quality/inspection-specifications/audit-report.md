# M055 — Inspection Specifications Audit

Audit date: 2026-08-25  
Domain: Quality  
Roles: `system_admin`, `qc_inspector`  
Dependencies read for context only: `customer-product-pricing`, `production-routings`, `inventory-master`, the quality inspection lifecycle

## Re-audit reason and outcome

This is a fresh audit because the previous report was written before the current
working-tree changes. The revision model, revision migration, integrity migration,
HTTP boundary test, and editor test all post-date that report; the existing
`fix-log.md` contains no completed fixes. The previous findings about list search,
archive/restore symmetry, signed decimals, cross-field tolerance validation,
view-only editor affordances, SPC status filtering, missing-SPC messaging, and
editor control labels/scrolling are addressed in the current code.

The module is implemented end to end and the normal authoring path now creates
revision-linked item rows. It is not verified. The current blockers are broken
quality demo/bootstrap fixtures, a revision history that is stored but not
available through the module's role-facing API/UI, incomplete database lineage
constraints, an SPC zero-variance contract defect, and an unresolved policy
boundary between current-revision and historical SPC. No application source was
changed during this re-audit.

## Discovery

### Present

- The API exposes options, list, show, product lookup, upsert, archive, restore,
  and SPC routes in `api/app/Modules/Quality/routes.php:37-57` and applies
  separate view/manage permission middleware in `api/app/Modules/Quality/routes.php:38-54`.
- `InspectionSpecService` supports product search, active/archive filtering,
  hashed product filters, transactional upsert, soft-deleted archive/restore,
  and revision-linked item replacement in
  `api/app/Modules/Quality/Services/InspectionSpecService.php:27-55` and
  `api/app/Modules/Quality/Services/InspectionSpecService.php:89-170`.
- `InspectionSpecRevision` and its item lineage relation exist in
  `api/app/Modules/Quality/Models/InspectionSpecRevision.php:15-43`; inspection
  creation pins the current revision in
  `api/app/Modules/Quality/Services/InspectionService.php:314-368`.
- The revision migration preserves old item rows and changes measurement item
  deletion to restrictive behavior in
  `api/database/migrations/2026_08_25_170000_create_inspection_spec_revisions.php:14-91`.
- Signed-decimal validation and parameter-type/tolerance invariants are enforced
  at the HTTP boundary in
  `api/app/Modules/Quality/Requests/UpsertInspectionSpecRequest.php:31-143`.
- SPC joins measurements to inspections and includes only passed/failed rows in
  `api/app/Modules/Quality/Services/SpcService.php:129-158` and
  `api/app/Modules/Quality/Services/SpcService.php:172-201`.
- The SPA has list search/status filters, archive/restore controls, archived
  detail mode, view-only rendering, accessible parameter controls, notes, a
  horizontally scrollable editor table, and an insufficient-data message in
  `spa/src/pages/quality/inspection-specs/index.tsx:36-145` and
  `spa/src/pages/quality/inspection-specs/editor.tsx:120-227` and
  `spa/src/pages/quality/inspection-specs/editor.tsx:299-549`.
- Focused API and SPA tests now exist in
  `api/tests/Feature/Quality/InspectionSpecBoundaryTest.php:42-183` and
  `spa/src/pages/quality/inspection-specs/editor.test.tsx:94-118`.

### Deliberately not found

- There is no inspection-spec revision-history route, revision resource, or
  revision-history UI. The only revision data returned by the spec resource is
  current-revision metadata, not prior revision item definitions, in
  `api/app/Modules/Quality/Resources/InspectionSpecResource.php:30-55`.
- The database integrity migration checks inspection and measurement values but
  does not check `inspection_spec_items` parameter/tolerance invariants or the
  same-spec relationship between an item/inspection and its revision in
  `api/database/migrations/2026_08_25_200000_add_inspection_integrity_checks.php:20-35`.
- There is no focused regression coverage for zero-variance SPC, revision-history
  retrieval, cross-spec lineage mismatches, soft-deleted products in the list,
  or the demo/bootstrap seeders. The current boundary suite covers API lifecycle,
  search, permissions, validation, revision creation, and status inclusion, but
  not those cases, in `api/tests/Feature/Quality/InspectionSpecBoundaryTest.php:42-183`.
- No money calculation is in scope, and this module does not own a state machine;
  inspection lifecycle transition behavior was read only for revision pinning
  context.

## Findings

### Discovery / functional correctness

#### F-01 — Broken: demo seeding creates specs that cannot drive the current inspection flow

`ComprehensiveDemoSeeder` truncates `inspection_specs`, `inspection_spec_items`,
and measurements but omits the new `inspection_spec_revisions` table in
`api/database/seeders/ComprehensiveDemoSeeder.php:74-99`. It then creates
inspections before specs in `api/database/seeders/ComprehensiveDemoSeeder.php:46-67`
and inserts specs/items directly without a revision ID in
`api/database/seeders/ComprehensiveDemoSeeder.php:980-1004`. The normal inspection
service now rejects an active spec without a current immutable revision in
`api/app/Modules/Quality/Services/InspectionService.php:314-327`.

The same fixture family inserts a Golden Path inspection with a spec ID but no
revision ID in `api/database/seeders/GoldenPathDemoSeeder.php:434-455`. On a clean
demo seed, the spec list can therefore show rows that cannot open a new inspection,
and rerunning the comprehensive seeder can leave stale/orphaned revision rows.
The measurements inserted afterward are also attached to inspections created
without `inspection_spec_id` in `api/database/seeders/ComprehensiveDemoSeeder.php:1007-1046`,
so the current SPC query's `i.inspection_spec_id` filter at
`api/app/Modules/Quality/Services/SpcService.php:139-143` excludes that fixture
evidence.

Impact: the documented/demo path presents quality data that is not usable by the
same module's current authoring, inspection, or SPC behavior.

#### F-02 — Broken: seeded dimensional bounds violate the API contract and evaluator semantics

The comprehensive fixture stores nominal values such as `100` with
`tolerance_min=-0.5` and `tolerance_max=0.5` in
`api/database/seeders/ComprehensiveDemoSeeder.php:965-974` and writes them
directly at `api/database/seeders/ComprehensiveDemoSeeder.php:990-1002`.
The request now requires a dimensional nominal and both bounds, and requires the
nominal to be inside those bounds, in
`api/app/Modules/Quality/Requests/UpsertInspectionSpecRequest.php:81-105`.
The evaluator compares the measured value directly with the stored bounds in
`api/app/Modules/Quality/Models/InspectionSpecItem.php:57-63`; it does not add the
nominal to tolerance offsets.

The fixture therefore creates rows that the editor cannot save through the public
contract, and its generated measurements use the offset interpretation in
`api/database/seeders/ComprehensiveDemoSeeder.php:1024-1044`, which disagrees with
the evaluator. Seeded inspection outcomes and SPC evidence are consequently
misleading even if the missing revision linkage is repaired.

#### F-03 — Incomplete: revision lineage exists in storage but is not inspectable by either role

The new model retains prior item rows per revision in
`api/app/Modules/Quality/Models/InspectionSpecRevision.php:38-43`, and the service
soft-deletes the former current rows while creating new revision-linked rows in
`api/app/Modules/Quality/Services/InspectionSpecService.php:116-142`. However,
`InspectionSpecService::show()` loads only the current items and current-revision
creator metadata in `api/app/Modules/Quality/Services/InspectionSpecService.php:57-64`.
`InspectionSpecResource` exposes only current-revision ID/version/notes/creator
metadata and the current item collection in
`api/app/Modules/Quality/Resources/InspectionSpecResource.php:30-55`; no route in
`api/app/Modules/Quality/routes.php:42-54` returns a selected revision or its
historical item definitions, and the editor has no revision history view.

The database can retain old tolerances, but a `system_admin` or `qc_inspector`
using the supported module surface cannot reconstruct which parameter definition
was superseded or compare revisions. That is an auditability gap, not merely an
unused relation.

#### F-04 — Broken: zero-variance SPC violates its own contract and reports artificial capability

`SpcService::compute()` documents that an effectively zero sigma returns `null` in
`api/app/Modules/Quality/Services/SpcService.php:74-82`, but clamps sigma to
`1e-10` and proceeds to calculate Cp/Cpk in
`api/app/Modules/Quality/Services/SpcService.php:90-112`. Five or more identical
readings therefore produce very large rounded capability values instead of an
explicitly unavailable/undefined result.

Impact: a constant or quantized measurement stream can appear highly capable in
the QC panel, even though the method's stated contract says the result is not
computable. The pure math test suite has no all-identical-reading case.

### Hardening

#### F-05 — Incomplete: revision foreign keys do not enforce same-spec lineage

The revision migration adds independent foreign keys from item and inspection to
`inspection_spec_revisions` in
`api/database/migrations/2026_08_25_170000_create_inspection_spec_revisions.php:30-47`.
It does not enforce that `inspection_spec_items.inspection_spec_id` matches the
revision's `inspection_spec_id`, or that an inspection's `inspection_spec_id`
matches its pinned revision. The application service sets matching values during
normal upsert/inspection creation at
`api/app/Modules/Quality/Services/InspectionSpecService.php:130-133` and
`api/app/Modules/Quality/Services/InspectionService.php:349-356`, but the models
remain fillable and the direct seeders/tests bypass those service boundaries.

A mismatched pair can survive the database and make a historical inspection point
at one product's revision while its spec root points at another; SPC and lineage
queries then have no single authoritative definition. The integrity migration's
table list omits these checks at
`api/database/migrations/2026_08_25_200000_add_inspection_integrity_checks.php:20-35`.
Whether to use composite foreign keys or a domain-level invariant should be an
explicit design decision, but the current independent FKs leave the lineage open.

#### F-06 — Incomplete: spec-item invariants are request-only, while direct writers can bypass them

The request enforces parameter enum values, dimensional/visual/functional rules,
unique names, signed decimals, and tolerance geometry in
`api/app/Modules/Quality/Requests/UpsertInspectionSpecRequest.php:49-105`.
The base item table still has a free-form `parameter_type` and nullable decimal
bounds without database checks in
`api/database/migrations/0088_create_inspection_spec_items_table.php:25-43`.
The newer integrity migration adds checks for measurements, but not for spec items,
as shown by `api/database/migrations/2026_08_25_200000_add_inspection_integrity_checks.php:27-35`.

The quality seeders and several existing feature fixtures write these rows directly.
An invalid direct row can later fail enum casting or create an impossible inspection
gate even though the HTTP endpoint is safe. Decide which invariants belong at the
database boundary and add migration-backed coverage for them.

#### F-07 — Incomplete: current-vs-historical SPC scope is internally inconsistent and undocumented

The controller documents SPC as “all historical inspection measurements” in
`api/app/Modules/Quality/Controllers/InspectionSpecController.php:79-85`, while
the service documents “all current items” in
`api/app/Modules/Quality/Services/SpcService.php:115-127`. Because upsert
soft-deletes old items at
`api/app/Modules/Quality/Services/InspectionSpecService.php:123-127` and
`computeForSpec()` queries the current item ID at
`api/app/Modules/Quality/Services/SpcService.php:131-149`, measurements governed by
superseded item rows are excluded from the current-spec panel. The capability-study
endpoint separately permits historical soft-deleted item IDs through
`api/app/Modules/Quality/Services/SpcService.php:162-189`.

This may be an intentional “current revision only” policy or may understate the
historical process capability. The module has no explicit policy field, response
metadata, or regression test that settles the question. It must be decided and
documented before SPC is used as a quality gate; this is intentionally recorded as
a question rather than assuming one population is correct.

### Polish / frontend completeness

#### F-08 — Polish: SPC failures are silent in the editor

The editor enables the SPC query whenever a spec ID exists in
`spa/src/pages/quality/inspection-specs/editor.tsx:120-128`, but renders the SPC
section only when both `spcData.data` and threshold data are truthy in
`spa/src/pages/quality/inspection-specs/editor.tsx:460-519`. A request error or
loading state produces no status, retry affordance, or explanation; the entire
panel simply disappears. The existing insufficient-sample message is good for an
empty successful response, but it does not cover transport/server failure.

The QC role needs to distinguish “not enough completed readings” from “SPC could
not be loaded” before relying on the capability panel.

#### F-09 — Polish: the list loading skeleton has the wrong column count

The list declares six columns, including the action column, in
`spa/src/pages/quality/inspection-specs/index.tsx:53-96`, but the loading state
requests `SkeletonTable columns={5}` at
`spa/src/pages/quality/inspection-specs/index.tsx:122`. This causes a visible
layout mismatch during loading and is easy to correct within the existing design
system.

## Good controls confirmed

- Search is now consumed by the API and tested at
  `api/app/Modules/Quality/Services/InspectionSpecService.php:46-50` and
  `api/tests/Feature/Quality/InspectionSpecBoundaryTest.php:73-91`.
- Archive and restore are symmetric, transactional, row-locked, and route binding
  includes trashed records at `api/app/Modules/Quality/Services/InspectionSpecService.php:149-170`
  and `api/app/Modules/Quality/routes.php:42-54`.
- Upsert uses `DB::transaction()`/`lockForUpdate()` and appends revision-linked
  items at `api/app/Modules/Quality/Services/InspectionSpecService.php:89-145`.
- Read/manage permissions agree across routes, request authorization, and SPA
  affordances at `api/app/Modules/Quality/routes.php:38-54`,
  `api/app/Modules/Quality/Requests/UpsertInspectionSpecRequest.php:19-29`, and
  `spa/src/pages/quality/inspection-specs/editor.tsx:120-123`.
- The editor now has accessible names, notes editing, a responsive table wrapper,
  read-only view mode, and explicit insufficient-SPC-data copy at
  `spa/src/pages/quality/inspection-specs/editor.tsx:349-424` and
  `spa/src/pages/quality/inspection-specs/editor.tsx:460-518`, consistent with
  `docs/DESIGN-SYSTEM.md:523-532`.
- Money/centavo arithmetic is not part of this module, and the inspection lifecycle
  remains protected by its separate state machine in the dependency context.

## Verification record

- `php -l` on the inspection-spec controllers, models, request, resource, services,
  revision/integrity migrations, and boundary test: passed.
- `php artisan route:list --path=quality/inspection-specs -v`: passed; seven routes
  with expected auth/feature/permission middleware.
- `php artisan test --filter=SpcServiceTest`: passed, 9 tests / 30 assertions.
- `php artisan test --filter=InspectionSpecBoundaryTest`: blocked before assertions;
  all three cases failed because PostgreSQL host `db` could not be resolved
  (`SQLSTATE[08006]`). Retrying with `DB_CONNECTION=sqlite DB_DATABASE=:memory:`
  was also blocked because the PHP SQLite driver is unavailable.
- `npx eslint` on the inspection-spec SPA/API files: passed.
- `npx tsc --noEmit`: failed on pre-existing unrelated files
  `src/pages/assets/detail.tsx` (missing `qrcode` types and an implicit `any`) and
  `src/pages/return-management/detail.tsx` (duplicate JSX attribute); no
  inspection-spec file was reported.
- `npx vitest run src/pages/quality/inspection-specs/editor.test.tsx`: could not
  start because the Vite temp directory is root-owned (`EACCES`); using the runner
  config loader instead reached a pre-existing `__dirname is not defined` config
  error. The test file was inspected but not executed.

## Recommendation

Release as `📋 Plan Ready`. The next session should repair and test the demo/bootstrap
fixtures first, then settle revision-history access and the current-vs-historical
SPC policy before adding database lineage checks. The zero-variance and small SPA
polish fixes can follow in the same dedicated implementation session, but the
module should not be marked verified until the boundary suite runs against a live
database and the seeded quality path is exercised end to end.
