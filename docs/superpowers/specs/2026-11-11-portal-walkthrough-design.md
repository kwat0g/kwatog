# Customer and Supplier Portal Walkthrough Design

## Intent

Help customer and supplier users understand where to go and what the important areas and actions on each portal page do. The current first-run tour groups pages by sidebar section, but it does not explain the current page's controls in context.

## Goals

- Keep a short, optional first-run overview of the portal and its navigation.
- Make Help open guidance for the page the user is currently viewing.
- Cover each customer and supplier navigation page, plus record detail and create/edit screens.
- Explain each page's purpose, its main content areas, and its important workflow actions.
- Point to real page elements so the explanation is connected to the control or area it describes.
- Keep the guide easy to replay, skip, close, and navigate with a keyboard.
- Use brief motion to show the active step and spotlight moving between targets. Honor reduced-motion preferences.

## Non-goals

- Do not require users to finish the walkthrough before using the portal.
- Do not change portal workflows or the behavior of the controls being explained.
- Do not explain every table row action or transient dialog; focus on page structure, recurring controls, and the primary or distinctive actions.
- Do not add a new animation dependency.

## Constraints

- Preserve the established Atelier design system and current portal layout.
- Keep first-run completion and dismissal stored per portal user; retain Help as the replay path.
- Support narrow screens, keyboard use, and screen readers. The guide must not block page actions.
- Use the existing React, React Router, and CSS/Tailwind stack.

## Approaches considered

1. **Animate and clarify the existing overview.** Smallest change, but still leaves users to find controls on each page.
2. **Page-aware guide with spotlights (chosen).** Keeps the short overview and adds concise route-specific guidance through Help. It takes more route metadata, but explains controls where users encounter them.
3. **Persistent tips on every page.** Makes help visible without opening a tour, but adds permanent visual noise across dense operational screens.

## Design

The first visit retains a brief overview of the sidebar groups and portal tasks. Each page's Help action opens a short guide for that route, with steps for the page purpose, its main content area, and its important actions. Route-specific guidance covers the customer and supplier navigation destinations and their detail, create, and edit screens. Shared list and form patterns may share explanations where their behavior is the same.

Each step names the actual page area or control it describes and highlights that target without preventing interaction. The guide remains a compact, non-modal panel with progress, Previous/Next, Skip, Close, and Finish actions. Navigation to another page updates the page context; Help can be used again at any time.

Step changes use a short fade and small positional transition. The active target receives a clear accent outline; any pulse runs once per step. Reduced-motion mode removes spatial movement and pulsing while keeping the current step and target distinguishable.

## Implementation calls

- Add route-aware guide metadata and stable target hooks to portal page regions and key actions.
- Reuse shared targets for common page headers, tables, and forms; add specific targets only where a portal workflow needs explanation.
- Preserve the existing per-user dismissal behavior and Help replay. A completed or skipped first-run overview will not reopen automatically.
- Keep copy factual and concise; describe what an action does and what users can expect after using it.

## Review points

- Verify that every listed customer and supplier route has page-specific guidance and a valid target, including detail and form routes.
- Verify navigation, skip, close, replay, keyboard operation, responsive placement, and reduced-motion behavior.
- Leave existing unrelated worktree changes untouched.
