# Landing & Auth — Atelier Rebuild, Contact Us Consolidation

**Date:** 2026-08-07
**Status:** Approved design, not yet planned
**Scope:** `spa/src/pages/landing/` (31 files), `spa/src/pages/auth/` (4 pages), `layouts/AuthLayout.tsx`, the `--landing-*` token namespace, `quote_requests` → `contact_inquiries`, new CRM inquiry inbox
**Relates to:** `docs/superpowers/specs/2026-08-07-atelier-frontend-redesign.md` — this is track **T10**, deferred from that spec and now widened to cover auth and the orphaned quote path

---

## 1. Why this shape

The request began as "replace quote_requests with Contact Us" and opened to "full landing and login rebuild." Reading the code narrowed it.

| Stated driver | What the code shows | Conclusion |
|---|---|---|
| 3D section is misleading — no 3D features in the ERP | `three/parts.ts:66-97` builds parametric `LatheGeometry` from hand-authored `ring()`/`dome()` half-profiles. The geometry is honest product illustration; the *copy* claims "Interactive 3D Catalogue" and "real CAD geometries". | Fix the claims, keep the WebGL. |
| Request Quote is wrong — Ogami takes no custom mold parts | Form asks `part_description` ("Material grade, tightest tolerance, surface finish") plus a drawing upload. That is custom-tooling intake. | Replace with general enquiry. |
| — (not raised; found while reading) | `POST /landing/quote-request` has a controller, service, model, enum, throttle — and **zero ERP consumer**. No inbox, no sidebar entry, no page across 23 route files. Submissions are unreadable. | Orphaned write path. Must be closed. |
| — (not raised; found while reading) | `ContactSection.tsx:34-39` and `QuoteModal.tsx:15-22` declare the **same** Zod schema and post to the same endpoint. Two copies of one form. | Consolidate, don't add. |
| Landing looks unlike the product | Landing runs a parallel `--landing-*` palette (near-black ink accent, translucent surfaces) and is exempt from `audit:tokens`. | Retheme onto Atelier tokens. |

The landing page's engineering is sound: disposal-correct WebGL, `inert` on the menu overlay, reduced-motion and no-WebGL fallbacks throughout. What is wrong with it is *claims* and *token namespace*. Both are fixable without touching that work.

**Decision:** retheme onto Atelier, prune false claims, consolidate two forms into one Contact Us, and give the resulting inquiries somewhere to land.

## 2. Non-goals

- No section-layout rewrite. `HeroSection`, `ProcessSection`, `StatsSection`, `QualitySection`, `PhilippinesSection`, `MarqueeSection`, `CapabilitiesSection` keep their structure and choreography.
- No replacement of GSAP, three.js, or Lenis.
- No change to the ERP `quotes` / `quote_items` tables (migration `0237`). Despite the name collision these are a real CRM feature, unrelated to the landing form.
- No change to the 355 ERP pages, routing, guards, or the three Atelier palettes as shipped.
- Not fixing the 38 pre-existing `/portal/*` 401s or the 8 pre-existing `any` lint errors — separate debt, already tracked.

## 3. Token unification

### 3.1 What goes

Landing currently declares a parallel palette in `tokens.css:94-112` (light) and `:184-194` (dark):

```
--landing-canvas #fafaf9     --landing-accent #1c1917   (near-black ink)
--landing-surface rgba(245,244,241,0.85)                (translucent)
--landing-elevated rgba(239,237,234,0.95)
--landing-border rgba(227,224,219,0.8)
```

`LandingPage.tsx:62-69` and `AuthLayout.tsx:35-39` then remap the shared accent onto it via an inline `style` object (`WARM_ACCENT`) so ERP primitives render monochrome on these surfaces.

That mechanism is **deleted, not repointed**. Landing and auth consume Atelier tokens directly: `bg-canvas`, `bg-surface`, `text-primary`, `text-muted`, `border-default`, and the clay `--accent`.

### 3.2 What survives

The blueprint grid and technical line-work are load-bearing for the engineering register, and auth uses them too. Three tokens survive, renamed and re-derived from Atelier ink:

```css
--blueprint-grid:      color-mix(in srgb, var(--text-primary) 5%, transparent);
--blueprint-line:      color-mix(in srgb, var(--text-primary) 14%, transparent);
--blueprint-grid-size: 32px;
```

These are the only landing-scoped tokens permitted after this work. The `audit:tokens` allowlist names them explicitly.

### 3.3 Consequences

- **Landing gains dark mode.** It has a `--landing-*` dark block today but the theme toggle is not exposed on the marketing site. On Atelier tokens it follows `data-theme` like everything else.
- **Translucency dies.** Atelier surfaces are opaque. This is the `backdrop-blur` removal (T3) finally reaching the 6 landing files it skipped.
- **The accent flips from near-black ink to clay (`#b4542a`).** The marketing site stops being monochrome. This is the largest visible change in the job and is intentional: brand consistency with the ERP was the point.
- **`audit:tokens` scope widens.** Landing is exempt today. After this it is not.

## 4. Truth fixes

Copy is DB-seeded via migration, not hardcoded — so most of it is admin-editable and already honest. Migration `0406` reads "Every molded part is a controlled geometry." The lies are in the **hardcoded fallbacks**, which render only when the API fails — worse than a visible lie, because they appear when nobody is watching.

| Claim | Source | Replacement |
|---|---|---|
| "Interactive 3D Catalogue" | `PartShowcaseSection.tsx:40` fallback, and migration `0435` eyebrow | "Parts We Mold" |
| "Rotate real CAD geometries, inspect material grades, tolerances, and disassemble into exploded engineering views." | `PartShowcaseSection.tsx:42` fallback | "Representative geometries of components we produce." |
| "Inspect Moulded Components & Specs" | `:41` fallback | "Components We Produce" |

Migration `0448` amends `0435`'s stored eyebrow. The TSX fallbacks are corrected in place.

The WebGL stays: four wireframe parts (wiper bushing, pivot cap, filler cap, spacer collar) — Ogami's own products — framed as an illustrative gallery rather than a CAD inspection tool.

## 5. Contact Us consolidation

### 5.1 Frontend

Two duplicate forms collapse to one. `ContactSection` keeps the form; `QuoteModal.tsx` (295 LOC) and `FloatingQuoteButton.tsx` (60 LOC) are deleted along with the `quoteOpen` state and `inertWhen(quoteOpen)` handling in `LandingPage.tsx`.

The hero CTA and nav CTA become anchor links to `#contact`. `heroCta.quote_href` is already `'#contact'` in the seeded content, so that path exists — only the modal-opening handler is removed.

New schema:

```ts
const inquirySchema = z.object({
  full_name: z.string().min(1, 'Full name is required'),
  company:   z.string().optional(),   // job seekers / general enquiries have none
  email:     z.string().min(1, 'Email is required').email('Invalid email'),
  phone:     z.string().optional(),
  message:   z.string().min(1, 'Message is required').max(2000),
});
```

Dropped: `part_description`, `annual_volume`, `drawing`. The upload UI, its preview, and the `Remove drawing` control go with them — as does the `multipart/form-data` path, so the submit becomes a plain JSON POST.

CTA label changes from "Request Quote" to "Contact Us" in the seeded `hero_cta.quote_label` and the `?? 'Request Quote'` fallbacks at `ContactSection.tsx` / `HeroSection.tsx:51`.

### 5.2 Backend

Migration `0447` reshapes `quote_requests` into `contact_inquiries` rather than creating a fresh table — the throttle, `ip_address`, and `user_agent` columns are worth keeping:

| Was | Becomes |
|---|---|
| `request_no` | `inquiry_no` |
| `part_description` | `message` (text) |
| `annual_volume`, `drawing_path`, `drawing_original_name` | dropped |
| — | `phone` (nullable), `converted_to_lead_id` (nullable FK → `leads`, `nullOnDelete`) |
| `company` | `company` (now nullable) |

`QuoteRequestStatus` → `ContactInquiryStatus`: `new`, `in_progress`, `converted`, `closed`.

Endpoint `POST /landing/contact-inquiry` — still `throttle:public-form`, still unauthenticated by design (it is a public marketing form). `StoreContactInquiryRequest` validates; `ContactInquiryService` assigns `inquiry_no` via `DocumentSequenceService` (`INQ-YYYYMM-NNNN`) inside `DB::transaction()`. That requires a `documents.sequence_config` setting entry — see §11.

Model uses `HasHashId`; the resource returns `hash_id`, never the integer `id`.

## 6. CRM inquiry inbox

This is what stops the rebuild from recreating the orphaned write path with nicer walls.

- **`/crm/inquiries`** — list page honouring all 5 states from `docs/PATTERNS.md` (loading skeleton, error+retry, empty, data, stale), filterable by status
- **`/crm/inquiries/:id`** — detail page showing the message, contact fields, submission metadata, and status controls
- **Sidebar entry** under *Sales & CRM*, positioned after Leads, following the existing shape:
  `{ to: '/crm/inquiries', label: 'Inquiries', icon: Inbox, feature: 'crm', permission: 'crm.inquiries.view' }`
  No `badgeKey` — see §11; the prop has no provider and would be dead weight.
- **Permissions** `crm.inquiries.view` / `crm.inquiries.manage`, added to `RolePermissionSeeder` in the Sales Pipeline block (after `crm.leads.manage`, line ~284), granted to the roles already holding `crm.leads.manage`
- **Convert to Lead** — the deliberate promote step. Creates a `Lead` with `source: LeadSource::Website` (the enum case exists and is currently unused), copies `company_name`/`contact_person`/`email`/`phone`, carries `message` into `notes`, sets inquiry `status: converted` and `converted_to_lead_id`. One `DB::transaction()`; navigates to the new lead on success.

Routing to a separate inbox rather than straight into `leads` is deliberate: a contact form catches job seekers, supplier pitches, and general questions. Force-feeding those into the CRM funnel would pollute it. The convert action is the gate.

## 7. Auth pages

`AuthLayout.tsx` already imports `AutoPartShowcase`, `CrosshairCursor`, `motion.ts` and `landingApi` from `pages/landing/`, so it retheme with landing whether or not that is intended. Work here is token substitution plus the same accent-remap deletion:

- Delete the `WARM_ACCENT`-equivalent remap at `AuthLayout.tsx:35-39`
- Grid background at `:44-46` reads `--blueprint-grid` / `--blueprint-grid-size`
- `bg-landing-canvas` / `text-landing-text` at `:128` become `bg-canvas` / `text-primary`
- The GSAP entrance and `quickTo` parallax at `:76-99` are untouched
- `login.tsx` (315 LOC), `forgot-password.tsx`, `reset-password.tsx`, `change-password.tsx` — token classes only; no change to auth flow, CSRF handling, or session behaviour

Auth semantics are explicitly out of scope. The cookie-based Sanctum flow, the CSRF pre-flight, and the redirect behaviour stay exactly as they are.

## 8. Work breakdown

| ID | Track | Touches | Depends on |
|---|---|---|---|
| **L1** | Retire `--landing-*`; add `--blueprint-*`; widen `audit:tokens` to cover landing | `tokens.css`, `tailwind.config.ts`, audit script | — |
| **L2** | Landing retheme — 31 files onto Atelier tokens; delete `WARM_ACCENT` | `pages/landing/` | L1 |
| **L3** | Auth retheme — 4 pages + `AuthLayout` | `pages/auth/`, `layouts/` | L1 |
| **L4** | Truth fixes — TSX fallbacks + migration `0448` amending `0435` | 2 files, 1 migration | — |
| **L5** | Backend: migrations `0447` (reshape) + `0449` (sequence config), enum, model, request, service, controller, route | `Modules/Landing/` | — |
| **L6** | Frontend form consolidation — delete `QuoteModal` + `FloatingQuoteButton`, rewrite `ContactSection` form | 4 files | L5 |
| **L7** | CRM inbox — list, detail, API layer, types, routes, sidebar, permissions | ~8 files | L5 |
| **L8** | Convert-to-Lead — service + action + test | 3 files | L7 |

L1–L4 are independent of L5–L8. Either half can ship alone.

## 9. Verification

| Gate | Command | Expectation |
|---|---|---|
| Types | `npm run typecheck` | clean |
| Lint | `npm run lint` | no new errors (8 pre-existing `any` remain) |
| Unit | `npm run test:run` | all pass |
| Tokens | `npm run audit:tokens` | clean, now including `pages/landing/` |
| Routes | `npm run audit:live-routes` | `/crm/inquiries` renders; the 38 pre-existing `/portal/*` 401s unchanged in count |
| RBAC | `npm run audit:rbac`, `npm run audit:role-permissions` | new permissions present and granted |
| Build | `npm run build` | clean |
| Backend | api suite | all pass, incl. new inquiry tests |

New tests:

- Contact inquiry submit — validation, throttle, reachable unauthenticated, `inquiry_no` format
- Convert-to-lead — creates lead with `source=website`, marks inquiry `converted`, rolls back wholly on failure
- Permission gate — `/crm/inquiries` refused without `crm.inquiries.view`
- Token discipline — assert no `--landing-*` reference survives outside the `--blueprint-*` allowlist
- Fallback integrity — reduced-motion and no-WebGL paths still render `ProfileSilhouette` after retheme
- Copy assertion — the strings "CAD", "Catalogue" do not appear in `pages/landing/`

## 10. Risks

| Risk | Mitigation |
|---|---|
| Clay accent on the marketing site reads wrong at full-page scale | L2 is its own track with a screenshot diff; revertible without touching L5–L8 |
| Removing translucency breaks layering in the 6 landing files T3 skipped | Same track, same diff; these are the files T3 already deferred for this reason |
| Reshaping `quote_requests` loses existing submissions | Table is unread by any UI, so rows have no consumer today; still, the migration renames columns rather than dropping the table, and `down()` restores the prior shape |
| Contact form becomes a spam sink once the drawing gate is gone | `throttle:public-form` retained; `message` capped at 2000 chars; inbox has a `closed` status |
| Auth retheme breaks the login flow | Token classes only; no touch to CSRF, session, or redirect logic. `audit:live-routes` covers `/login` |
| `--blueprint-*` becomes a new escape hatch for arbitrary landing colours | Allowlist names exactly three tokens; anything else fails the gate |

## 11. Open items

- **No sidebar badge.** `badgeKey` has no provider anywhere in `spa/src` outside `Sidebar.tsx` — the prop is declarative only and currently inert for every item that declares it, including `pending_so`. The inbox therefore ships **without** a badge; adding one means building the count-provider mechanism, which is out of scope here. The sidebar entry omits `badgeKey` entirely rather than declaring a dead prop.
- `INQ-YYYYMM-NNNN` requires an entry in the `documents.sequence_config` **setting** — `DocumentSequenceService::config()` reads it via `SettingsService` and throws `InvalidArgumentException` on an unknown type. A migration adds `'contact_inquiry' => ['prefix' => 'INQ', 'reset' => 'monthly', 'pad' => 4]` to that setting. A `document_sequences` table row is created lazily by `generate()` and needs no seeding.
- Whether the theme toggle should be exposed on the public marketing site now that landing follows `data-theme`. Defaulting to no — the site renders in light unless the user's ERP preference is already dark.

