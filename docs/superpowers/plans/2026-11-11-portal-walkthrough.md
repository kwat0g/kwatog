# Portal Walkthrough Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the customer and supplier portal walkthrough explain each page's main areas and actions with a concise, optional, page-aware guide.

**Architecture:** Keep the existing first-run overview and add route-specific guide metadata in the portal walkthrough module. The walkthrough resolves stable targets from shared page components, highlights the current target without blocking the page, and uses the existing React/CSS stack for short transitions.

**Tech Stack:** React 18, TypeScript, React Router 7, Tailwind CSS, existing CSS motion tokens.

**Spec:** [2026-11-11-portal-walkthrough-design.md](../specs/2026-11-11-portal-walkthrough-design.md)

## Global Constraints

- Preserve the established Atelier design system and current portal layout.
- Keep the guide optional and non-blocking; retain per-user first-run dismissal and Help replay.
- Cover all customer and supplier navigation pages, plus record detail, create, and edit screens.
- Explain page purpose, main content areas, and important workflow actions; skip transient dialogs and secondary row actions.
- Use brief motion to show the active step and spotlight moving between targets; honor reduced-motion preferences.
- Use the existing React, React Router, and CSS/Tailwind stack; add no animation dependency.
- Do not modify unrelated worktree changes.

## Review Focus

- Route matching must prefer specific paths such as `/orders/new` and `/rfqs/:id/quote` over record-detail patterns.
- Missing or conditionally rendered targets must leave the guide usable and give the user a clear next step.
- Route changes while the guide is open must reset the page-specific step without losing the overview or dismissal state.
- Highlighting must not cover controls, steal focus, or prevent normal pointer interaction.
- The panel and target treatment must remain readable on narrow screens and with reduced motion enabled.

---

### Task 1: Define route-specific guide content

**Files:**
- Modify: `spa/src/components/portal/portalWalkthroughs.ts`

**Interfaces:**
- Add `PortalGuideTarget`, a union of the shared target names used by the guide: `page-header`, `page-actions`, `filters`, `records`, `form`, `section`, and `navigation`.
- Allow a guide step to select a specific occurrence of a repeated target, such as a particular dashboard panel.
- Add `PortalPageGuide` with `title`, `description`, and `steps: PortalGuideStep[]`.
- Add `portalPageGuideForPath(realm: 'customer' | 'supplier', pathname: string): PortalPageGuide | undefined`.
- Keep the existing overview step data and `portalGuideStorageKey` behavior.

- [x] **Step 1: Add a route catalog for each protected customer and supplier portal screen**
  Cover the navigation destinations and their record detail, create, and edit paths. Match static paths before dynamic record paths, and use concise factual explanations of the page's purpose and important actions.

- [x] **Step 2: Review the catalog against `spa/src/routes/portalRoutes.tsx`**
  Confirm every protected portal path pattern resolves to the intended guide, including dynamic IDs and nested action routes.

---

### Task 2: Expose stable guide targets in shared UI

**Files:**
- Modify: `spa/src/components/layout/PageHeader.tsx`
- Modify: `spa/src/layouts/PortalLayout.tsx`
- Modify: `spa/src/pages/portal/customer/pricing/index.tsx`
- Modify: `spa/src/pages/notifications/index.tsx`
- Modify: `spa/src/pages/portal/customer/statement-of-account.tsx`
- Modify: `spa/src/pages/portal/supplier/statement-of-account.tsx`
- Modify: `spa/src/pages/portal/customer/dashboard.tsx`
- Modify: `spa/src/pages/portal/supplier/dashboard.tsx`
- Modify: `spa/src/pages/portal/customer/orders/detail.tsx`
- Modify: `spa/src/pages/portal/supplier/purchase-orders/detail.tsx`
- Modify: `spa/src/pages/portal/customer/orders/new.tsx`
- Modify: `spa/src/pages/portal/customer/delivery-schedules.tsx`
- Modify: `spa/src/pages/portal/customer/complaints/index.tsx`
- Modify: `spa/src/pages/portal/customer/returns/new.tsx`
- Modify: `spa/src/pages/return-management/cases/create.tsx`
- Modify: `spa/src/pages/portal/supplier/item-listings.tsx`
- Modify: `spa/src/pages/portal/supplier/rfqs/quote.tsx`

**Interfaces:**
- Shared `data-layout` values already identify page headers, filter bars, data tables, and panels; use them for `page-header`, `filters`, `records`, and `section` targets.
- Add `data-layout="page-actions"` to the PageHeader action slot.
- Add `data-portal-guide-target="form"` to the primary workflow regions listed above. A couple of those workflows are panels or fieldsets rather than literal `<form>` elements.
- Mark the shared notification filter and account statement summaries, and add `data-portal-guide-target="navigation"` to the portal sidebar.
- Mark key line-item and shipment regions so detail-page steps can spotlight the specific record table.

- [x] **Step 1: Mark the PageHeader action slot**
  Add the stable `page-actions` target without changing its layout or button behavior.

- [x] **Step 2: Mark primary portal workflow regions**
  Add the form target to each listed workflow, including the customer pricing request panel; add stable targets for navigation, notification filters, and statement summaries. Exclude search forms and transient dialogs.

- [x] **Step 3: Review shared target coverage**
  Confirm each `PortalGuideTarget` resolves to the intended region on the list, detail, and form screens that use it.

---

### Task 3: Add route-aware Help and spotlight behavior

**Files:**
- Modify: `spa/src/components/portal/PortalWalkthrough.tsx`
- Create: `spa/src/components/portal/PortalWalkthrough.css`

**Interfaces:**
- Consume `portalPageGuideForPath(realm, pathname)` from Task 1.
- Resolve the active step's target using the shared `data-layout` values or the explicit form target from Task 2.

- [x] **Step 1: Separate first-run overview state from current-page Help state**
  First run opens the existing overview. Help opens the current route's guide. Overview links navigate to the chosen portal page and switch into that page's guide.

- [x] **Step 2: Add page-specific Previous/Next progress**
  Keep the existing skip, close, finish, and replay behavior. A route change updates the page context and starts at that page's first guide step.

- [x] **Step 3: Highlight the current target and recover when it is absent**
  Add and clean up an active-target marker through an effect. Keep the page interactive and show the explanation without a spotlight when no target is available.

- [x] **Step 4: Add restrained entrance and step-change motion**
  Animate the guide panel and active-target state with short Atelier motion tokens. Disable spatial movement and pulse effects under `prefers-reduced-motion` while retaining a visible active state.

- [x] **Step 5: Preserve keyboard and screen-reader use**
  Announce step changes, support Escape to close, keep visible focus states, and return focus to Help when the guide closes.

---

### Task 4: Review route coverage and rendered states

**Files:**
- Review: `spa/src/routes/portalRoutes.tsx`
- Review: `spa/src/components/portal/portalWalkthroughs.ts`
- Review: `spa/src/components/portal/PortalWalkthrough.tsx`

- [x] **Step 1: Compare all customer and supplier route patterns with the guide catalog**
  Check top-level navigation, dynamic details, create/edit forms, and nested quote routes.

- [x] **Step 2: Inspect the guide at desktop and phone widths**
  Check overview, page-specific steps, missing-target fallback, page navigation, close/replay, keyboard access, and reduced-motion appearance.

**Validation constraint:** Do not add or run tests in this task unless the user requests testing or implementation verification.
