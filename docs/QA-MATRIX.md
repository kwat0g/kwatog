# OGAMI ERP — Cross-Browser & Device QA Matrix

> Sprint 8 — Task 83. Manual smoke test grid run before each defense rehearsal.
>
> **How to use:** open the page in each browser at the listed viewport width,
> drive the happy-path interaction, then paste a screenshot link or check the
> box. Flag broken layout / visual regressions in the **Notes** column.

## Browsers

| Slug         | Vendor             | Min version |
|---           |---                 |---          |
| chrome       | Google Chrome      | 120         |
| firefox      | Mozilla Firefox    | 121         |
| safari       | Apple Safari       | 17          |
| edge         | Microsoft Edge     | 120         |
| samsung      | Samsung Internet   | 22          |

## Devices

| Slug        | Form factor    | Width  | OS           |
|---          |---             |---     |---           |
| desktop-fhd | desktop        | 1920   | any          |
| desktop-md  | desktop        | 1440   | any          |
| desktop-sm  | small desktop  | 1280   | any          |
| tablet      | tablet         | 768    | iPadOS 17    |
| phone       | mobile phone   | 390    | Android 14   |

## Pages × Browsers (desktop-md = 1440px)

| Page                                  | chrome | firefox | safari | edge | Notes |
|---                                    |--------|---------|--------|------|-------|
| `/login`                              | ☐      | ☐       | ☐      | ☐    |       |
| `/dashboard/plant-manager`            | ☐      | ☐       | ☐      | ☐    |       |
| `/dashboard/hr`                       | ☐      | ☐       | ☐      | ☐    |       |
| `/dashboard/accounting`               | ☐      | ☐       | ☐      | ☐    |       |
| `/hr/employees`                       | ☐      | ☐       | ☐      | ☐    |       |
| `/hr/employees/{id}`                  | ☐      | ☐       | ☐      | ☐    |       |
| `/hr/separations`                     | ☐      | ☐       | ☐      | ☐    |       |
| `/payroll/periods`                    | ☐      | ☐       | ☐      | ☐    |       |
| `/inventory/items`                    | ☐      | ☐       | ☐      | ☐    |       |
| `/purchasing/purchase-orders`         | ☐      | ☐       | ☐      | ☐    |       |
| `/crm/sales-orders`                   | ☐      | ☐       | ☐      | ☐    |       |
| `/production/dashboard`               | ☐      | ☐       | ☐      | ☐    |       |
| `/production/schedule` (Gantt)        | ☐      | ☐       | ☐      | ☐    |       |
| `/quality/dashboard`                  | ☐      | ☐       | ☐      | ☐    |       |
| `/quality/inspections`                | ☐      | ☐       | ☐      | ☐    |       |
| `/maintenance/work-orders`            | ☐      | ☐       | ☐      | ☐    |       |
| `/maintenance/schedules`              | ☐      | ☐       | ☐      | ☐    |       |
| `/assets`                             | ☐      | ☐       | ☐      | ☐    |       |
| `/admin/audit-logs`                   | ☐      | ☐       | ☐      | ☐    |       |

## Self-service portal × phone (390px)

| Page                                | chrome | safari | samsung | Notes |
|---                                  |--------|--------|---------|-------|
| `/self-service`                     | ☐      | ☐      | ☐       |       |
| `/self-service/dtr`                 | ☐      | ☐      | ☐       |       |
| `/self-service/leave`               | ☐      | ☐      | ☐       |       |
| `/self-service/payslips`            | ☐      | ☐      | ☐       |       |
| `/self-service/notification-preferences` | ☐ | ☐    | ☐       |       |

## Width-specific spot checks

| Behaviour                                            | desktop-fhd | desktop-md | desktop-sm | tablet | phone |
|---                                                   |---          |---         |---         |---     |---    |
| Sidebar fully expanded                               | ☐           | ☐          | ☐          | —      | —     |
| Sidebar auto-collapses to 56px rail                  | —           | —          | —          | ☐      | —     |
| Self-service bottom navigation visible               | —           | —          | —          | —      | ☐     |
| Page header actions wrap to 2 lines without overlap  | ☐           | ☐          | ☐          | ☐      | —     |
| Data table horizontal scrolls (no clipping)          | ☐           | ☐          | ☐          | ☐      | ☐     |
| KPI cards reflow to grid responsively                | ☐           | ☐          | ☐          | ☐      | ☐     |

## Dark mode

Every page above must toggle between **light** and **dark** via the topbar
theme button without:

- ☐ Flash of unstyled content
- ☐ Hard-coded colors that bypass `--bg-canvas` / `--text-primary` etc.
- ☐ Charts (Recharts) keeping their light-mode palette
- ☐ Status chips / KPI deltas losing legibility

## Accessibility quick check (axe DevTools or Lighthouse)

For Plant Manager dashboard, Sales Order detail, and Self-service home:

- ☐ No critical violations
- ☐ Focus ring visible on keyboard tab
- ☐ Screen reader announces page title and primary actions

## Reduced motion

- ☐ With `prefers-reduced-motion: reduce` enabled, modal/dropdown
      animations shorten to 0ms; the rest of the UI behaves normally.

---

## How to file a regression

1. Take a screenshot.
2. Open a follow-up commit on the sprint branch with format:
   `fix(qa): <page> · <browser> · <issue>`.
3. Reference this matrix row in the commit body.
