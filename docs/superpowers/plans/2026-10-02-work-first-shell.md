# Work-First ERP Shell Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the shared ERP shell surface urgent work first while keeping routes, permissions, badges, and mobile behavior intact.

**Architecture:** Reuse `SECTIONS` and existing visibility logic as the only navigation metadata source. `Sidebar` will render a persistent Work block followed by directly visible section headers; each section keeps its own expansion state, while Expand all/Collapse all stays pinned in the sidebar footer. `Topbar` will keep wide-screen utility controls while exposing the same actions through `ProfileDropdown` at smaller desktop widths.

**Tech Stack:** React, TypeScript, React Router, Zustand, Tailwind utility classes, existing focus and tooltip primitives.

**Spec:** `docs/superpowers/specs/2026-10-02-work-first-shell-design.md`

## Global Constraints

- No backend, route, permission, badge API, or command-palette changes.
- No new navigation taxonomy or role-specific configuration.
- Preserve mobile drawer focus trap, Escape handling, route-close behavior, and reduced-motion classes.
- No test-suite expansion; run existing typecheck, token audit, detector, and focused static checks after implementation.

---

### Task 1: Add Work-first navigation grouping

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

**Interfaces:**
- Consumes: existing `SECTIONS`, `isNavItemVisible`, `useBadges`, `useSidebarStore`, and route matching state.
- Produces: unchanged exported `SECTIONS` and `NavItem`/`NavSection` types; rendered Work block and directly visible module sections.

- [x] **Step 1: Add explicit work metadata to existing navigation items**

Add optional `work?: boolean` to `NavItem`. Mark only Dashboard, Action Center, Approval Queue, and Notifications in `SECTIONS[0]` as work items. Leave KPI Scorecard, Business Chain Tracker, and Automation Recovery routable in the module-section list.

- [x] **Step 2: Derive visible work and module sections from existing permission-filtered sections**

After `visibleSections` is computed, derive:

```ts
const workItems = visibleSections.flatMap((section) => section.visibleItems.filter((item) => item.work));
const moduleSections = visibleSections
  .map((section) => ({ ...section, visibleItems: section.visibleItems.filter((item) => !item.work) }))
  .filter((section) => section.visibleItems.length > 0);
```

Use these derived values for rendering only. Keep route matching based on `visibleSections` so active state and deep links do not change.

- [x] **Step 3: Render Work before directly visible module sections**

Extract the existing section-item rendering into a local `renderSection(section, mobile)` helper. Render a labelled Work group containing `workItems`, preserving `NavLink`, badges, collapsed labels, and focus-ring classes. Render `moduleSections` directly below Work so users can scan domains without an extra disclosure.

- [x] **Step 4: Preserve active-domain behavior and pin expansion controls**

Keep the current section expansion state and active-domain auto-open behavior. Render Expand all/Collapse all after the scrollable navigation region so the controls stay pinned to the bottom when more than one module section is visible.

- [x] **Step 5: Preserve mobile and collapsed-rail behavior**

Use the same `sidebarContent` tree for desktop and mobile. In the collapsed rail, keep Work and module icons visible, scale nav icons to 20px, and retain the explicit Expand navigation affordance. Do not change drawer focus, scroll locking, route-close effects, or the 56px rail width.

- [x] **Step 6: Run focused static checks**

Run `npx tsc --noEmit -p spa/tsconfig.json` and inspect the rendered JSX diff for stale disclosure state or references to `visibleSections`.

- [x] **Step 7: Run collapsed-rail visual smoke check**

Render the shell at a desktop viewport, collapse the sidebar, capture the rail, and confirm the rail remains 56px wide with 20px nav icons and an 18px Expand navigation chevron.

### Task 2: Move medium-width utility actions into the profile menu

**Files:**
- Modify: `spa/src/components/layout/ProfileDropdown.tsx`
- Modify: `spa/src/components/layout/Topbar.tsx`
- Modify: `spa/src/layouts/AppLayout.tsx`

**Interfaces:**
- Consumes: existing `useTheme().toggle`, `resolvedTheme`, `KeyboardShortcutHelp` state setter, and `ProfileDropdown` user/logout props.
- Produces: optional `onToggleTheme`, `theme`, and `onOpenShortcuts` callbacks on `ProfileDropdown`; no route or state-store changes.

- [x] **Step 1: Add optional utility callbacks to ProfileDropdown**

Extend `ProfileDropdownProps` with `theme?: 'light' | 'dark'`, `onToggleTheme?: () => void`, and `onOpenShortcuts?: () => void`. Render these as menu buttons only when their callbacks exist, using existing `focusRingInset`, `LuSun`/`LuMoon`, and `LuCircleHelp` icons. Close the menu before invoking each callback.

- [x] **Step 2: Pass utility callbacks from Topbar/AppLayout**

Keep `useTheme()` and `useKeyboardShortcuts()` ownership where they already live. Pass the callbacks through `Topbar` to `ProfileDropdown`; keep `AppLayout` as the source of `setHelpOpen` and pass it through `rightExtras` only as needed by the existing desktop control.

- [x] **Step 3: Apply responsive visibility without removing access**

Keep direct theme and shortcut buttons on wide screens. Hide those direct buttons at medium widths with responsive utility classes and render the profile-menu actions at those widths. Keep the mobile profile menu reachable and ensure keyboard shortcuts remain available through the global `?` and Ctrl/Cmd+K handlers.

- [x] **Step 4: Run focused static checks**

Run `npx tsc --noEmit -p spa/tsconfig.json` and inspect keyboard/focus paths for duplicate actions or inaccessible utility controls.

### Task 3: Verify shell acceptance conditions

**Files:**
- Verify only: `spa/src/components/layout/Sidebar.tsx`, `spa/src/components/layout/Topbar.tsx`, `spa/src/components/layout/ProfileDropdown.tsx`, `spa/src/layouts/AppLayout.tsx`

- [x] **Step 1: Run typecheck**

Run `npm run typecheck` from `spa/`. Expected: exit code 0.

- [x] **Step 2: Run token discipline audit**

Run `npm run audit:tokens` from `spa/`. Expected: token audit passes.

- [x] **Step 3: Run Impeccable detector**

Run:

```bash
/home/ec2-user/.agents/skills/impeccable/scripts/impeccable detect --json \
  spa/src/components/layout/Sidebar.tsx \
  spa/src/components/layout/Topbar.tsx \
  spa/src/components/layout/ProfileDropdown.tsx \
  spa/src/layouts/AppLayout.tsx
```

Expected: no new findings.

- [x] **Step 4: Check diff formatting**

Run `git diff --check`. Expected: no whitespace errors.
