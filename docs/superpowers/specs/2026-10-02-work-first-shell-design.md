# Work-First ERP Shell

## Context

The shared ERP shell exposes many permission-filtered module groups at the same navigation level. Users must classify work before reaching the urgent queue. The topbar also gives theme and shortcut controls the same visual weight as search, notifications, and page context.

## Goal

Make the shell work-first without changing routes, permissions, badge sources, or mobile navigation behavior.

## User experience

- A persistent **Work** block exposes Dashboard, Action Center, Approval Queue, and Notifications.
- Domain navigation sits behind one **All modules** disclosure. The active domain opens automatically so deep links retain context.
- Existing module sections keep their labels, ordering, permission gates, badges, keyboard behavior, and command-palette destinations.
- The mobile drawer uses the same Work/All modules hierarchy. Its focus trap, Escape handling, and route-close behavior remain unchanged.
- Theme and keyboard-help actions move into the profile menu at medium widths. Wide screens retain the existing direct controls.

## Components and data flow

### Sidebar

- Preserve `SECTIONS` as the single navigation metadata source.
- Mark the existing Overview work destinations with a small metadata flag; keep non-work Overview links available under All modules.
- Render work links first, then one disclosure around all non-work sections.
- Auto-open All modules when the active route belongs to a domain section.
- Keep section badges meaningful by rendering item descriptions and retaining existing section-level totals only inside All modules.
- Keep the collapsed rail behavior, but retain active-state and tooltip semantics.

### Topbar, AppLayout, ProfileDropdown

- Add optional profile-menu actions for theme switching and keyboard help.
- Keep direct theme/help controls on wide screens; hide them at medium widths where they compete with breadcrumbs and search.
- Profile actions close the menu before invoking their callbacks.
- Keep command-palette keyboard shortcuts and theme persistence unchanged.

## States and edge cases

- No visible work items: render the Work block with the available permission-filtered items; do not invent placeholders.
- Active domain route: All modules opens and the matching section remains expanded.
- Collapsed desktop rail: work and domain links remain keyboard reachable with existing labels and titles.
- Mobile drawer: Work and All modules fit the existing scroll container and focus trap.
- Missing user: profile actions do not render because the profile menu already returns null.
- Reduced motion: reuse existing transition and focus classes.

## Scope boundaries

- No backend, route, permission, badge API, or command-palette changes.
- No new navigation taxonomy or role-specific configuration.
- No visual redesign of page content.
- No test-suite expansion; run existing typecheck, token audit, detector, and focused static checks after implementation.

## Acceptance criteria

1. First viewport presents work destinations before domain taxonomy.
2. Domain destinations require one explicit All modules action unless the current route belongs to that domain.
3. Existing permission filtering, badge counts, route matching, active states, mobile focus behavior, and command-palette destinations remain correct.
4. Theme and keyboard-help actions remain reachable at every supported width.
5. No new detector findings or token-audit failures.
