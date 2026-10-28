# Maintenance Presence Handoff Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the Maintenance Supervisor assign a worker and attest physical presence without starting the MWO, then let the assigned Maintenance Technician start it. Consolidate the MWO Details and Description into one top-of-content panel on desktop and mobile.

**Architecture:** Keep `maintenance.wo.assign` as the supervisor confirmation gate and keep the existing work-order presence event. Separate assignment UI from the start dialog in desktop and mobile views; the assigned technician starts through the existing start endpoint.

**Tech Stack:** Laravel, PHPUnit, React, TypeScript, React Query, Vitest.

**Spec:** User-approved workflow in conversation: Maintenance Supervisor confirms physical presence and assigns; assigned Maintenance Technician starts; Department Head receives no new permission.

## Global Constraints

- Only an otherwise eligible Maintenance worker with unverified attendance may use the physical-presence checkbox; it does not bypass other blockers.
- Keep `maintenance.wo.assign` as the permission for assignment and confirmation. Seeded Maintenance Supervisor holds it; do not grant Maintenance permissions to `department_head`.
- Assignment and presence confirmation must not start an MWO. Start remains an explicit action by an authorized executor.
- Presence confirmation is a work-order audit event; it does not create or change an HR attendance record.
- Preserve legacy `presence_attestation_reason` compatibility and existing assignment/start permission checks.

---

### Task 1: Make supervisor assignment a separate action

**Files:**
- Modify: `spa/src/pages/maintenance/work-orders/AssignmentPanel.tsx`
- Modify: `spa/src/pages/maintenance/work-orders/detail.tsx`
- Modify: `spa/src/pages/maintenance/mobile/work-order.tsx`
- Test: `spa/src/pages/maintenance/work-orders/AssignmentPanel.test.tsx`

**Interfaces:**
- `AssignmentPanel` performs assignment and presence confirmation only. Optional `onAssigned` callback fires after successful save; it never starts the MWO.
- Desktop and mobile pages provide separate Assign worker and Confirm start actions. Assignment modal closes after assignment; start dialog contains only the start action. Details and Description share one panel at the top of the content.

- [x] **Step 1: Add a failing panel test** that selects an unverified current assignee, checks physical presence, submits assignment, and verifies the callback fires without invoking a start callback.

```tsx
expect(workOrdersApi.assign).toHaveBeenCalledWith(order.id, worker.id, expect.objectContaining({
  expected_assignee_id: worker.id,
  presence_confirmed: true,
}));
expect(onAssigned).toHaveBeenCalledOnce();
```

- [x] **Step 2: Run the focused test and confirm it fails** because an unchanged assignee cannot save confirmation and assignment success currently triggers start.

```bash
cd spa && node_modules/.bin/vitest run src/pages/maintenance/work-orders/AssignmentPanel.test.tsx
```

- [x] **Step 3: Make the panel assignment-only.** Let it save when the assignee changes or when an unverified current assignee is confirmed. Replace `onConfirmStart` with `onAssigned`; remove start/cancel/pending controls from the panel.
- [x] **Step 4: Separate page actions on desktop and mobile.** Keep Assignment available to users with `maintenance.wo.assign`. Open an assignment-only dialog for the supervisor. Keep Confirm start in a separate dialog; assigned Maintenance Technicians use that action.
- [x] **Step 5: Run the focused test and targeted ESLint.** Confirm checkbox assignment succeeds for an unverified candidate, known blockers remain disabled, and assignment does not start work.

```bash
cd spa && node_modules/.bin/vitest run src/pages/maintenance/work-orders/AssignmentPanel.test.tsx
cd spa && node_modules/.bin/eslint src/pages/maintenance/work-orders/AssignmentPanel.tsx src/pages/maintenance/work-orders/detail.tsx src/pages/maintenance/mobile/work-order.tsx src/pages/maintenance/work-orders/AssignmentPanel.test.tsx --max-warnings 0
```

**Stop condition:** Supervisor assignment closes without transitioning the MWO. Assigned Technician can start later through the separate start action.

### Task 2: Make confirmation visible and explain missing confirmation

**Files:**
- Modify: `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`
- Test: `api/tests/Feature/Maintenance/MaintenanceAssigneeSuggestionTest.php`

**Interfaces:**
- Checkbox confirmation remains an immutable `maintenance.presence_confirmed` event tied to the current MWO assignment.
- MWO work history states that physical presence was confirmed. A blocked start tells the user to ask the Maintenance Supervisor to confirm presence in Assignment.

- [x] **Step 1: Add failing service tests** for supervisor checkbox assignment followed by assigned Maintenance Technician start, explicit presence wording in the MWO log, and start rejection with a clear supervisor instruction when no confirmation exists.

```php
$service->assign($order, $technician->employee_id, $supervisor, [
    'expected_assignee_id' => null,
    'presence_confirmed' => true,
]);
$service->start($order, $technician);
$this->assertSame(MaintenanceWorkOrderStatus::InProgress, $order->fresh()->status);
```

- [x] **Step 2: Run the focused backend test and confirm the new assertions fail.**

```bash
cd api && php artisan test tests/Feature/Maintenance/MaintenanceAssigneeSuggestionTest.php
```

- [x] **Step 3: Append `Physical presence confirmed.` to the existing lifecycle log only when the checkbox path is used.** Keep the immutable activity event and legacy reason behavior.
- [x] **Step 4: Replace the unverified-attendance start error with a clear instruction to ask the Maintenance Supervisor to confirm presence in the work-order Assignment panel.** Leave absence, shift, workload, and eligibility blockers unchanged.
- [x] **Step 5: Re-run the focused backend test and PHP formatting check.**

```bash
cd api && php artisan test tests/Feature/Maintenance/MaintenanceAssigneeSuggestionTest.php
cd api && vendor/bin/pint --test app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php
```

**Stop condition:** Supervisor confirmation is visible in the MWO history; Technician start succeeds only after an eligible assignment and valid presence confirmation.

### Task 3: Verify the completed handoff

- [x] Run the affected AssignmentPanel tests, Maintenance assignee feature tests, targeted ESLint, TypeScript check, PHP syntax check, and `git diff --check`.
- [x] Confirm the diff preserves unrelated workspace changes and does not change role grants, attendance rows, schema, or existing MWO assignments.

**Verification note:** The SPA typecheck ran and reported existing project errors in unrelated files/modules; focused Vitest, ESLint, PHPUnit, Pint, and diff checks pass.

**Stop condition:** Assignment, confirmation, and start remain separate, role-gated actions on desktop and mobile.
