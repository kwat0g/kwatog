# OGAMI ERP — New Tasks V2 (Features, Automation, Polish)

> Tasks beyond the original 85 + A/P series. These cover the gaps identified after the full system audit.
> Organized into 7 series. Execute in series order within each group.
>
> **Goal:** A fully automated, self-managing ERP where humans handle exceptions — not routine work.
>
> **How to execute:**
> `claude "Read CLAUDE.md, docs/PATTERNS.md, docs/DESIGN-SYSTEM.md. Then execute Task [CODE] from docs/NEW-TASKS-V2.md completely."`

---

## SERIES U — USER ACCOUNTS & SELF-SERVICE

*The employee is a first-class user of the system. Right now they're just a database record.*

---

### Task U1: User Account Creation Linked to Employee Record

**The gap:** HR creates employees but the employee has no system login. User accounts and employee records are separate — no enforced link. HR must manually create both.

**What to build:**

**Backend:**

Add `user_id` (FK users, nullable) to `employees` table if not already bidirectionally linked. Create migration `0120_add_user_id_to_employees.php`.

Create `UserProvisioningService`:
```php
// app/Modules/HR/Services/UserProvisioningService.php

public function provisionForEmployee(Employee $employee, array $options = []): User
{
    return DB::transaction(function () use ($employee, $options) {
        // Generate credentials
        $email = $options['email'] ?? $this->generateEmail($employee);
        $tempPassword = $this->generateTempPassword(); // 12-char random

        $user = User::create([
            'name'                => $employee->full_name,
            'email'               => $email,
            'password'            => bcrypt($tempPassword, ['cost' => 12]),
            'role_id'             => $options['role_id'] ?? $this->defaultRoleForEmployee($employee),
            'employee_id'         => $employee->id,
            'must_change_password' => true,
            'is_active'           => true,
        ]);

        // Link back to employee
        $employee->update(['user_id' => $user->id]);

        // Store in password_history
        PasswordHistory::create([
            'user_id'       => $user->id,
            'password_hash' => $user->password,
        ]);

        // Send welcome email with credentials
        $user->notify(new WelcomeNotification($tempPassword));

        return $user;
    });
}

public function deactivateForEmployee(Employee $employee): void
{
    if ($employee->user) {
        $employee->user->update([
            'is_active' => false,
        ]);
        // Revoke all sessions
        $employee->user->tokens()->delete();
        DB::table('sessions')
            ->where('user_id', $employee->user->id)
            ->delete();
    }
}

private function generateEmail(Employee $employee): string
{
    $base = strtolower($employee->first_name . '.' . $employee->last_name);
    $base = preg_replace('/[^a-z.]/', '', $base);
    $domain = config('app.employee_email_domain', 'ogami.ph');
    // Handle duplicates: add number suffix
    $email = "{$base}@{$domain}";
    $count = 1;
    while (User::where('email', $email)->exists()) {
        $email = "{$base}{$count}@{$domain}";
        $count++;
    }
    return $email;
}
```

API endpoints:
- `POST /api/v1/employees/{employee}/provision-account` — creates linked user account, sends welcome email
- `POST /api/v1/employees/{employee}/deactivate-account` — deactivates user, revokes sessions
- `PATCH /api/v1/employees/{employee}/reset-password` — HR-initiated password reset, sends temp password
- `GET /api/v1/employees/{employee}/account-status` — returns linked user info (account exists, is_active, last_login)

**Frontend:**

On Employee detail page → Employment tab → "System Account" section:
```
┌─────────────────────────────────────────────────────┐
│ System Account                                       │
│                                                      │
│ Status:    [● Active]   Last login: Apr 06, 14:23   │
│ Email:     juan.cruz@ogami.ph                        │
│ Role:      Employee                                  │
│                                                      │
│ [Reset Password]  [Deactivate Account]               │
└─────────────────────────────────────────────────────┘
```

If no account exists:
```
┌─────────────────────────────────────────────────────┐
│ System Account                    No account yet     │
│                                                      │
│ This employee does not have a system login.          │
│                                                      │
│ [Create Account]                                     │
└─────────────────────────────────────────────────────┘
```

Create Account modal:
- Email (auto-filled from generation, editable)
- Role (dropdown: default = Employee, can set higher)
- "Send welcome email" toggle (default on)
- Submit → account created → modal closes → section updates

**Welcome email template** (`resources/views/emails/welcome.blade.php`):
```
Subject: Welcome to Ogami ERP — Your Account is Ready

Hi [Full Name],

Your Ogami ERP account has been created.

Login URL: [APP_URL]
Email: [email]
Temporary Password: [temp_password]

You will be required to change your password on first login.

— Ogami HR Department
```

**Bulk provisioning:** Employee list page → select multiple employees → bulk action "Create System Accounts" → creates accounts for all selected, sends welcome emails, shows progress modal.

---

### Task U2: User Management Module (Admin)

**The gap:** No dedicated user management page. Admins can't see all users, can't search, can't manage accounts centrally.

**What to build:**

Page: `pages/admin/users/index.tsx`

```
┌──────────────────────────────────────────────────────────────────┐
│ User Management          [213 users]      [Export] [Create User] │
├──────────────────────────────────────────────────────────────────┤
│ Search... │ Role: All ▾ │ Status: All ▾ │ Department: All ▾      │
├───────────┬─────────────────┬───────────┬──────────┬────────────┤
│ Name      │ Email           │ Role      │ Status   │ Last Login │
├───────────┼─────────────────┼───────────┼──────────┼────────────┤
│ Juan Cruz │ juan@ogami.ph   │ Employee  │ ● Active │ 2h ago     │
│ Ana Reyes │ ana@ogami.ph    │ Finance   │ ● Active │ Yesterday  │
│ (locked)  │ bob@ogami.ph    │ Employee  │ ⊘ Locked │ Apr 01     │
│ (inactive)│ old@ogami.ph    │ Employee  │ ○ Inactive│ Jan 15    │
└───────────┴─────────────────┴───────────┴──────────┴────────────┘
```

Table columns: Avatar+Name, Email (mono), Role (chip), Status (chip), Employee linked (link to employee), Last Login (mono), Actions (reset password, deactivate, unlock).

User detail page: `pages/admin/users/[id].tsx`
- Account info (email, role, status)
- Linked employee card (click → opens employee detail)
- Session info (last login, IP, user agent)
- Login history (last 10 logins with IP, device, success/fail)
- Password reset button
- Lock/unlock button
- Deactivate button (with confirmation: "This will log out all active sessions")
- Role change dropdown (immediate effect, no approval needed for admin)
- Permission overrides section (see Task R3)

API endpoints:
- `GET /api/v1/admin/users` — paginated, filterable
- `GET /api/v1/admin/users/{user}` — with login history
- `POST /api/v1/admin/users` — create standalone user (no employee link)
- `PATCH /api/v1/admin/users/{user}/unlock` — unlock locked account
- `PATCH /api/v1/admin/users/{user}/deactivate` — deactivate + revoke sessions
- `PATCH /api/v1/admin/users/{user}/role` — change role
- `PATCH /api/v1/admin/users/{user}/reset-password` — generate temp password + email
- `GET /api/v1/admin/users/{user}/login-history` — last 50 login events

DB: Create `login_history` table — id, user_id (FK), ip_address, user_agent, status (success/failed/locked), created_at. Log every login attempt in `LoginController`.

---

### Task U3: Complete Employee Self-Service Portal

**The gap:** Self-service exists but is incomplete. Factory workers need everything on their phone without calling HR.

**What to build (comprehensive):**

**Home page** (`pages/self-service/index.tsx`):
```
┌─────────────────────────────────┐
│ Good morning, Juan 👋            │
│ Thursday, May 7, 2026           │
├─────────────────────────────────┤
│ Today's Shift                   │
│ Day Shift · 6:00 AM – 2:00 PM  │
│ ● 6 hours remaining             │
├─────────────────────────────────┤
│ Leave Balance                   │
│ VL: 12.5  SL: 14.0  SIL: 5.0  │
├─────────────────────────────────┤
│ Pending Requests                │
│ Leave request · Under review    │
├─────────────────────────────────┤
│ Latest Payslip                  │
│ Apr 1–15 · Net: ₱ 9,450.00     │
│ [View]  [Download PDF]          │
└─────────────────────────────────┘
```

**DTR page** (`pages/self-service/dtr.tsx`):
- Month/year selector
- Calendar grid: each day shows shift + time_in + time_out + hours
- Color coded: present (subtle green dot), absent (red dot), holiday (blue dot), on_leave (amber dot), rest_day (gray)
- Tap day → detail popup: exact time in/out, late/undertime, computed hours, OT if any
- Monthly summary row at bottom: total hours, total OT, total absences, total tardiness

**Leave page** (`pages/self-service/leaves.tsx`):
- Balance cards per leave type (VL 12.5/15, SL 14.0/15, etc.)
- Leave history: list of past requests with status chips
- "Apply for Leave" button → bottom sheet form (mobile-optimized):
  - Leave type dropdown (shows remaining balance per type)
  - Date range picker
  - Reason text area
  - Upload supporting document (optional, required for SL 3+ days)
  - Submit → approval notification sent to Dept Head

**Payslip page** (`pages/self-service/payslips.tsx`):
- Card list of all payslips (period, gross, net)
- Tap → detail view:
  - Basic pay breakdown
  - All allowances
  - All deductions (itemized: SSS, PhilHealth, Pag-IBIG, tax, loan, CA)
  - Net pay (large, prominent)
  - "Download PDF" button
- Year-to-date summary: total gross, total tax, total contributions

**Loans page** (`pages/self-service/loans.tsx`):
- Active company loan card: principal, balance, monthly amortization, periods remaining
- Active cash advance card: amount, next deduction
- "Apply for Loan" button → form: type, amount, reason, requested periods
- Loan history: past loans (paid off)
- Amortization schedule toggle (show/hide per-period schedule)

**Profile page** (`pages/self-service/profile.tsx`):
- View-only: name, employee no, department, position, date hired, employment type
- Editable: mobile number, personal email, home address, emergency contact (name, relation, phone)
- Submit changes → HR notified for review (does not auto-apply — HR must confirm)
- Profile photo upload
- Government IDs: masked view (last 4 digits only) + "Request update" button → sends message to HR

**Bottom navigation** (all self-service pages):
```
[🏠 Home] [📅 DTR] [🏖 Leave] [💰 Payslip] [👤 Me]
```
44px height, full-width, grayscale background, icons with labels.

---

### Task U4: Employee Onboarding Workflow

**The gap:** New hire is a data entry exercise. Should be a guided workflow that sets everything up.

**What to build:**

When HR creates a new employee and saves, trigger the onboarding workflow:

Step 1 — Profile complete (auto: just created)
Step 2 — Assign shift (HR assigns from shift management)
Step 3 — Initialize leave balances (system auto-creates based on leave_types, pro-rated if mid-year)
Step 4 — Provision system account (HR clicks "Create Account" or bulk provision)
Step 5 — Assign to department team (department head notified)
Step 6 — Government IDs recorded (HR fills SSS, PhilHealth, Pag-IBIG, TIN)
Step 7 — Banking info recorded (for payroll bank file)
Step 8 — Onboarding complete ✓

**UI:** Horizontal stepper on Employee detail page (like ChainHeader but for onboarding). Each step has a check when complete. Incomplete steps show as action buttons.

```
Profile ✓ → Shift ✓ → Leave Balances ✓ → Account ○ → Dept Team ○ → Gov IDs ○ → Banking ○
                                           [Create]    [Notify]      [Fill]      [Fill]
```

**Automation:** Steps 1 and 3 are automatic. Steps 2, 4, 5, 6, 7 are triggered by HR actions. System tracks which steps are done — sends reminder to HR after 3 days if incomplete.

---

## SERIES C — CHAIN PROCESS AUTOMATION HARDENING

*Every manual step in a chain that can be automated should be automated. Humans only handle exceptions.*

---

### Task C1: Full Order-to-Cash Auto-Chain

**Current state:** Many chain steps require manual navigation and button clicks even when the decision is obvious.

**Target state:** SO confirmed → system does everything up to WO start automatically. Human only touches: output recording (supervisor), QC pass/fail decision, delivery confirmation.

**What to automate end-to-end:**

```
SO Confirmed (human triggers once)
    ↓ AUTO
MRP Engine runs (Task A1 already planned)
    ↓ AUTO
Work Orders created for each SO line (currently manual)
    ↓ AUTO
Materials reserved in inventory (currently: WO confirmed by PPC manually)
    ↓ AUTO
Capacity check via MRP II (auto-assign best available machine + mold)
    ↓ AUTO (if capacity available)
Production Schedule created and notified to Production Manager
    ↓ AUTO
Material Requisition / picking list generated and sent to Warehouse
    ↓ HUMAN (Warehouse issues materials — physical action required)
    ↓ AUTO on material issue
Work Order status → in_progress
    ↓ HUMAN (Supervisor records output every 2 hours)
    ↓ AUTO on WO completion
Outgoing QC Inspection record created (status: pending_inspection)
QC Inspector notified
    ↓ HUMAN (QC measures and records result)
    ↓ AUTO on QC pass
Delivery Note draft created
Warehouse notified to pick and pack
    ↓ HUMAN (Driver delivers, uploads signed receipt)
    ↓ AUTO on delivery confirmed
Draft Invoice created
Finance notified
    ↓ HUMAN (Finance reviews and finalizes)
    ↓ AUTO on invoice finalized
GL auto-posted
Customer notified via email
```

**Implementation:**

Create event listeners for each auto-step:

```php
// Event: SalesOrderConfirmed → Listener: InitiateOrderToCashChain
class InitiateOrderToCashChain
{
    public function handle(SalesOrderConfirmed $event): void
    {
        $so = $event->salesOrder;

        // Step 1: Run MRP for this SO
        $mrpPlan = $this->mrpEngine->runForSalesOrder($so);

        // Step 2: Create Work Orders for each SO line
        foreach ($so->items as $line) {
            $wo = $this->createWorkOrder($so, $line, $mrpPlan);

            // Step 3: Auto-capacity plan if machine/mold available
            $schedule = $this->capacityPlanner->autoSchedule($wo);

            if ($schedule) {
                // Step 4: Reserve materials
                $this->materialReservation->reserve($wo);

                // Step 5: Notify Production Manager
                $this->notify->productionManager(
                    "WO {$wo->wo_number} auto-scheduled on {$schedule->machine->name}"
                );
            } else {
                // Flag for PPC Head manual scheduling
                $this->notify->ppcHead(
                    "WO {$wo->wo_number} needs manual scheduling — no available machine/mold"
                );
            }
        }
    }
}

// Event: WorkOrderCompleted → Listener: TriggerOutgoingQC
class TriggerOutgoingQC
{
    public function handle(WorkOrderCompleted $event): void
    {
        $wo = $event->workOrder;

        // Auto-create pending outgoing inspection
        $inspection = Inspection::create([
            'inspection_number'    => $this->sequences->generate('inspection'),
            'stage'                => 'outgoing',
            'inspected_entity_type' => 'work_order',
            'inspected_entity_id'  => $wo->id,
            'product_id'           => $wo->product_id,
            'batch_quantity'       => $wo->quantity_good,
            'sample_size'          => $this->aql->calculateSampleSize($wo->quantity_good),
            'result'               => 'pending',
            'inspector_id'         => null, // assigned when QC inspector opens it
        ]);

        // Notify QC team
        $this->notify->qcTeam(
            "Outgoing inspection required: {$inspection->inspection_number} · {$wo->product->name} · {$wo->quantity_good} pcs"
        );
    }
}

// Event: InspectionPassed (outgoing) → Listener: CreateDeliveryDraft
class CreateDeliveryDraftOnQcPass
{
    public function handle(InspectionPassed $event): void
    {
        if ($event->inspection->stage !== 'outgoing') return;

        $wo = $event->inspection->workOrder;
        $so = $wo->salesOrder;

        if (!$so) return;

        // Auto-create delivery draft
        $delivery = Delivery::create([
            'delivery_note_number' => $this->sequences->generate('delivery'),
            'sales_order_id'       => $so->id,
            'customer_id'          => $so->customer_id,
            'scheduled_date'       => now()->addDay(),
            'status'               => 'scheduled',
        ]);

        DeliveryItem::create([
            'delivery_id'   => $delivery->id,
            'product_id'    => $wo->product_id,
            'quantity'      => $wo->quantity_good,
            'work_order_id' => $wo->id,
        ]);

        // Auto-generate CoC
        $this->cocService->generate($delivery);

        // Notify Warehouse
        $this->notify->warehouse(
            "New delivery scheduled: {$delivery->delivery_note_number} for {$so->customer->name}"
        );
    }
}
```

---

### Task C2: Full Procure-to-Pay Auto-Chain

**Target state:** PR approved → PO auto-created → supplier auto-emailed → GRN received → QC auto-triggered → Bill auto-drafted. Human only: approve PR, approve PO (if ≥ ₱50K), receive goods (physical), QC decision, final bill payment approval.

```
PR Approved (human: final approver clicks Approve)
    ↓ AUTO
PO created (consolidated by vendor, line items from approved PRs)
If total < ₱50,000:
    ↓ AUTO
    PO auto-approved, PDF auto-emailed to supplier
If total ≥ ₱50,000:
    ↓ HUMAN (VP approves PO)
    ↓ AUTO on VP approval
    PDF auto-emailed to supplier
    ↓ HUMAN (Warehouse receives goods physically)
    ↓ AUTO on GRN creation
    Incoming QC inspection record created (status: pending)
    QC Inspector notified
    ↓ HUMAN (QC inspects and records result)
    ↓ AUTO on QC pass
    GRN status → accepted, stock levels updated, weighted avg cost recalculated
    3-way match auto-triggered (PO vs GRN vs pending bill)
    Draft bill created (pre-filled from PO: vendor, items, amounts, VAT)
    Finance notified: "Review and post bill for PO {po_number}"
    ↓ HUMAN (Finance reviews draft bill, adjusts if needed, posts)
    ↓ AUTO on bill posted
    GL auto-posted (DR inventory/expense + DR VAT Input, CR AP)
    ↓ HUMAN (Finance schedules payment on due date)
    ↓ AUTO on payment recorded
    GL auto-posted (DR AP, CR Cash)
    Vendor payment history updated
    PO status → fully paid
```

**Implementation notes:**
- `PRApprovalCompleted` event → `ConsolidatePurchaseOrders` listener
- `PurchaseOrderApproved` event → `SendPOToSupplier` listener (email PO PDF)
- `GoodsReceiptCreated` event → `TriggerIncomingQC` listener
- `InspectionPassed` (incoming) event → `AcceptGRNAndDraftBill` listener
- All auto-created records tagged with `is_auto_generated = true` and `auto_generated_reason = 'chain_automation'`

---

### Task C3: Full Hire-to-Retire Auto-Chain

**Target state:** Employee created → system handles all setup. Payroll self-runs. Separation triggers automatic final pay.

```
Employee Created (HR fills form and saves)
    ↓ AUTO (Task U4 Onboarding)
Leave balances initialized for current year (pro-rated)
    ↓ AUTO
Welcome notification queued (pending account creation)
    ↓ HUMAN (HR provisions account, assigns shift)
    ↓ AUTO (14th and last day of month — Task A3)
Payroll period auto-created, computation queued
    ↓ AUTO on computation complete
HR notified to review
    ↓ HUMAN (HR reviews, approves)
    ↓ HUMAN (Finance confirms)
    ↓ AUTO on finalization
Payslips generated for all employees
Each employee notified: "Your payslip for [period] is ready"
Bank file CSV auto-generated
GL auto-posted
    ↓ AUTO (January 1st)
Leave balances reset per carry-over rules
Pro-rated SIL credited for new hires
    ↓ HUMAN (HR initiates separation)
    ↓ AUTO on separation initiated
Clearance record created with all department items
Each department head notified to sign off
    ↓ HUMAN (Each department signs off)
    ↓ AUTO when all clearance items signed
Final pay computed:
  - Last salary (pro-rated by working days)
  - Unused VL converted (if is_convertible_on_separation)
  - Unused SIL converted (if is_convertible_on_separation)
  - Pro-rated 13th month
  - MINUS outstanding loan balance
  - MINUS unreturned property value
Final pay payslip generated
BIR Form 2316 auto-generated
User account auto-deactivated
```

---

### Task C4: Real-Time Chain Progress Tracker

**The gap:** ChainHeader shows the steps but does not update in real-time when another user advances the chain. You must refresh to see it.

**What to build:**

When any chain step advances (SO status change, WO status change, inspection result, delivery status), broadcast a WebSocket event to all users viewing that record.

```php
// Broadcast on every chain-relevant status change
class ChainStepAdvanced implements ShouldBroadcast
{
    public function __construct(
        private string $entityType, // 'sales_order', 'purchase_order', etc.
        private string $entityHashId,
        private string $newStatus,
        private string $activeStep,
        private array $completedSteps,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("chain.{$this->entityType}.{$this->entityHashId}");
    }
}
```

```typescript
// spa/src/hooks/useChainProgress.ts
// Subscribe to chain updates for a specific record
export function useChainProgress(entityType: string, entityId: string) {
  const queryClient = useQueryClient();

  useEffect(() => {
    const channel = window.Echo.channel(`chain.${entityType}.${entityId}`);

    channel.listen('ChainStepAdvanced', (data: ChainStepEvent) => {
      // Invalidate the record query — TanStack Query refetches automatically
      queryClient.invalidateQueries({ queryKey: [entityType, entityId] });
      // Show toast: "Chain updated: [step] → [newStep]"
      toast.info(`${data.activeStep} — updated by another user`);
    });

    return () => channel.stopListening('ChainStepAdvanced');
  }, [entityType, entityId]);
}
```

Add `useChainProgress()` to: SalesOrder detail, PurchaseOrder detail, WorkOrder detail, Delivery detail.

---

### Task C5: Chain Bottleneck Detection

**The gap:** No visibility into WHERE orders get stuck in the chain.

**What to build:**

`ChainBottleneckService` — identifies records that have been at the same chain step for too long.

Thresholds:
- SO at "MRP Planned" for > 2 days → alert PPC
- WO at "Confirmed" for > 1 day (materials not issued) → alert Warehouse
- Outgoing inspection "pending" for > 4 hours → alert QC Head
- Delivery at "Scheduled" for > 1 day → alert ImpEx
- Invoice "draft" for > 1 day → alert Finance
- PR "pending" for > 2 days (not approved) → escalate (Task A7)
- Bill "unpaid" for > 30 days → alert Finance + AR aging update

Dashboard widget: "Chain Bottlenecks" — shows count per stage, clickable to filtered list.

API: `GET /api/v1/chain/bottlenecks` — returns grouped list of overdue records per chain step.

---

## SERIES E — PDF & CSV EXPORT ENHANCEMENT

*Every document the business needs must look professional and contain everything they need.*

---

### Task E1: PDF Enhancement (Branding + Quality Pass)

**For every PDF in the system, apply these standards:**

**Layout standards:**
```
┌─────────────────────────────────────────────────────┐
│  [OGAMI LOGO]                                        │
│  Philippine Ogami Corporation                        │
│  FCIE, Dasmariñas, Cavite · Tel: (046) XXX-XXXX    │
│  TIN: XXX-XXX-XXX-000 · VAT Reg.                   │
│  ─────────────────────────────────────────────────  │
│  [DOCUMENT TITLE]                  [DOC NUMBER]      │
│  [Date]                            [Other header]    │
│ ═════════════════════════════════════════════════   │
│ [BODY]                                               │
│ ─────────────────────────────────────────────────  │
│ [APPROVAL SIGNATURES]                                │
│ ─────────────────────────────────────────────────  │
│ Generated by: [name] on [date] at [time]   Page 1/1 │
└─────────────────────────────────────────────────────┘
```

**Specific document specs:**

*Payslip* (A5, 2 per A4 page):
- Two-column layout: left = earnings, right = deductions
- Horizontal rule separating each section
- Net pay boxed at bottom with larger font (14pt mono)
- CONFIDENTIAL watermark: diagonal 45°, 20% opacity gray
- QR code bottom-right: links to `/self-service/payslips/{id}` (employee can scan to verify)
- "For questions, contact HR at hr@ogami.ph"

*Purchase Order* (A4 portrait):
- Vendor detail box (top-right)
- Delivery instructions box
- Line items table: Item Code, Description, UOM, Qty, Unit Price, Total
- Subtotal / VAT 12% / Total Amount section (right-aligned)
- Payment terms
- Delivery date requested
- Note: "This PO is subject to our standard terms and conditions"
- 4-level signature block

*Invoice* (A4 portrait):
- "TAX INVOICE" header (required for VAT-registered)
- Invoice number + date + due date
- Customer TIN field
- Line items: Description, Qty, Unit Price, Amount
- "Amount in Words: [AMOUNT IN WORDS]" (e.g., "Four Hundred Eighty-Six Thousand Five Hundred Pesos Only")
- VAT breakdown: VATable sales, VAT amount, Total amount due
- "This document is valid for [30] days"
- CoC reference number

*Certificate of Conformance* (A4 landscape):
- Ogami letterhead
- Batch info table: Product, Part No., Batch No., Quantity, Production Date
- Inspection summary table: Parameter, Specification, Result, Judgment (P/F)
- "CERTIFIES THAT: The above-mentioned parts were inspected and found to conform to the specified requirements"
- QC Inspector signature + QC Manager signature
- Date of inspection
- Customer name

*8D Report* (A4, multi-page):
- Cover page: complaint number, customer, product, severity, date
- D1–D8 each on own section with proper label
- D4 (Root Cause): 5-Why table
- D5 (Corrective Action): table with action, responsible, due date, status
- D7 (Preventive Action): same table
- Signature: QC Manager + Vice President

*Payroll Register* (A4 landscape):
- One row per employee
- Columns: Employee No, Name, Department, Basic, OT, Night Diff, Holiday, Gross, SSS, PhilHealth, PagIBIG, Tax, Loans, Total Deductions, Net Pay
- Department subtotals (shaded row)
- Grand total row at bottom
- CONFIDENTIAL watermark

**PDF preview:** Before download, show in-browser preview (use browser's built-in PDF viewer via blob URL). Add "Preview" button next to "Download" on every PDF-generating action.

**Bulk PDF:** Select multiple records in any list → "Print Selected" → system generates a single PDF with all records separated by page breaks → single download.

---

### Task E2: CSV/Excel Export Enhancement

**The gap:** Export exists but dumps raw data. Needs to be human-readable.

**Standards for all exports:**

1. **Column headers:** Human-readable labels, not snake_case field names
   - `employee_no` → "Employee No."
   - `basic_monthly_salary` → "Monthly Salary (₱)"
   - `department_id` → "Department" (show name, not ID)

2. **Resolved relationships:** Show names, not IDs
   - `department_id: 3` → "Production"
   - `vendor_id: 12` → "Ogami Co., Ltd."
   - `status: in_progress` → "In Progress"

3. **Number formatting:**
   - Money: right-aligned, 2 decimal places, no currency symbol (CSV), ₱ prefix in Excel
   - Dates: "May 07, 2026" format (not ISO)
   - Numbers: no thousand separators in CSV (Excel adds them), with separators in Excel

4. **Excel-specific:** Use `Maatwebsite\Excel` for `.xlsx`. Include:
   - Header row with bold formatting
   - Alternating row colors (very light gray/white)
   - Auto-fit column widths
   - Freeze top row
   - Number cells formatted as Number (not Text)
   - Sheet name = document type + date range

5. **Configurable columns:** Before export, show "Select columns" modal. User checks/unchecks columns. Selection saved per user per module.

```tsx
// Column selector modal
<ColumnSelectorModal
  isOpen={showColumnSelector}
  columns={availableColumns}
  selected={selectedColumns}
  onConfirm={(cols) => { setSelectedColumns(cols); triggerExport(cols); }}
  onClose={() => setShowColumnSelector(false)}
/>
```

6. **Scheduled exports:** "Save export schedule" button. Set: columns, filters, frequency (daily/weekly/monthly), recipients (comma-separated emails). Runs as scheduled job, emails CSV attachment.

**Specific exports to build:**

| Export | Sheet(s) | Use |
|---|---|---|
| Employee Master List | 1 sheet | HR compliance |
| Payroll Register | 1 sheet per period | Finance |
| SSS R-3 | SSS format exactly | Government remittance |
| PhilHealth RF-1 | PhilHealth format | Government remittance |
| Pag-IBIG Remittance | Pag-IBIG format | Government remittance |
| BIR 1601-C data | BIR format | Government tax |
| BIR 2316 | One per employee | Annual tax certificate |
| Inventory Valuation | 1 sheet | Finance |
| Stock Card per item | 1 sheet per item | Warehouse |
| AR Aging | 4 sheets (current/30/60/90+) | Finance |
| AP Aging | Same | Finance |
| Attendance Summary | 1 sheet per period | HR |
| Daily Production Report | 1 sheet | Production |
| QC Defect Summary | 1 sheet | Quality |

**Government report formats:** SSS R-3, PhilHealth RF-1, Pag-IBIG remittance, BIR 1601-C — these must follow the EXACT column format required by each agency. Build them as separate export classes in `app/Modules/Payroll/Exports/`.

---

### Task E3: In-System Document Viewer

**The gap:** PDFs download immediately. No preview, no history, no archive.

**What to build:**

`DocumentVault` — every generated PDF is saved and accessible:

```php
// app/Common/Services/DocumentVaultService.php
public function store(string $pdf, string $type, int $entityId, int $generatedBy): Document
{
    $filename = "{$type}-{$entityId}-" . now()->format('YmdHis') . '.pdf';
    $path = Storage::disk('private')->put("documents/{$type}/{$filename}", $pdf);

    return Document::create([
        'document_type'   => $type,
        'entity_type'     => $type,
        'entity_id'       => $entityId,
        'file_path'       => $path,
        'file_name'       => $filename,
        'generated_by'    => $generatedBy,
        'generated_at'    => now(),
    ]);
}
```

API: `GET /api/v1/documents/{document}/view` — serves PDF with proper Content-Type for inline viewing.

Frontend: "Documents" tab on every major record (SO, PO, Employee, Payroll Period) shows list of all generated documents with:
- Document type (Payslip, Invoice, CoC, 8D Report)
- Generated by (name)
- Generated at (datetime mono)
- "View" button → opens PDF in browser (inline, not download)
- "Download" button → forces download
- "Email" button → sends PDF to configured recipient

---

## SERIES R — RBAC ENHANCEMENT

*The current RBAC is seed-only. Admins need full control without touching code.*

---

### Task R1: Dynamic Role Management UI

**The gap:** 12 roles are seeded and that's it. Admin can't create new roles, can't clone existing ones, can't see what permissions a role has at a glance.

**What to build:**

`pages/admin/roles/index.tsx`:
```
┌────────────────────────────────────────────────────────┐
│ Roles & Permissions           [12 roles]  [Create Role]│
├────────────────┬──────────────┬────────────┬───────────┤
│ Role Name      │ Type         │ Users      │ Actions   │
├────────────────┼──────────────┼────────────┼───────────┤
│ System Admin   │ System       │ 1          │ [Edit]    │
│ HR Officer     │ Custom       │ 3          │ [Edit][⋯] │
│ Finance Officer│ Custom       │ 2          │ [Edit][⋯] │
│ PPC Head       │ Custom       │ 1          │ [Edit][⋯] │
│ Plant Manager  │ Custom       │ 1          │ [Edit][⋯] │
│ Line Supervisor│ Custom       │ 5          │ [Edit][⋯] │ ← new custom role
└────────────────┴──────────────┴────────────┴───────────┘
```

[⋯] menu: Clone role, View users with this role, Delete (if no users).

`pages/admin/roles/create.tsx`:
- Role name input
- Description input
- "Start from scratch" or "Clone from existing role" toggle
- If clone: select source role dropdown
- Submit → creates role → redirect to permission editor

`pages/admin/roles/[id]/permissions.tsx` — THE KEY PAGE:
```
Role: HR Officer                     [Save Changes]
─────────────────────────────────────────────────────────

Filter: [All Modules ▾]    Search permissions...

HR MODULE                                    [Select All]
  ├─ Employees
  │   ✅ hr.employees.view          View employee list and details
  │   ✅ hr.employees.create        Create new employees
  │   ✅ hr.employees.edit          Edit employee information
  │   ✅ hr.employees.delete        Soft-delete employees
  │   ✅ hr.employees.view_sensitive View SSS, TIN, bank account
  │   ✅ hr.employees.export        Export employee data
  │   ✅ hr.employees.provision_account Create system accounts
  ├─ Departments
  │   ✅ hr.departments.view
  │   ✅ hr.departments.manage
  └─ Positions
      ✅ hr.positions.view
      ✅ hr.positions.manage

ATTENDANCE MODULE                            [Select All]
  ├─ DTR
  │   ✅ attendance.dtr.view        View all attendance records
  │   ✅ attendance.dtr.import      Import biometric CSV
  │   ✅ attendance.dtr.manual      Manual attendance entry
  │   ☐  attendance.dtr.delete     Delete attendance records
  ...
```

Matrix features:
- Module-level "Select All" / "Deselect All" checkbox
- Individual permission checkboxes with label + description
- Changes highlighted (blue chip: "3 changes unsaved")
- Save button only appears when there are unsaved changes
- Optimistic updates (checkbox toggles immediately, saves on button click)
- Changes logged in audit_logs with old permissions vs new permissions

---

### Task R2: Per-User Permission Overrides

**The gap:** Sometimes one specific user needs one extra permission beyond their role (or needs one removed). Currently impossible without changing the whole role.

**What to build:**

`user_permission_overrides` table:
```
id, user_id FK, permission_id FK, type (grant/revoke), granted_by FK users, reason text, expires_at timestamp nullable, created_at
```

Backend: Modify `CheckPermission` middleware to:
1. Check role permissions (existing)
2. Check overrides: if 'revoke' override exists → deny. If 'grant' override exists → allow.
3. Ignore expired overrides (expires_at < now())

Frontend — on User detail page, "Permission Overrides" section:
```
Additional Permissions                    [Add Override]
────────────────────────────────────────────────────────
hr.employees.view_sensitive   GRANTED   by Maria S.   Expires: May 31
production.wo.delete          REVOKED   by Ana R.     No expiry   [Remove]
```

Add Override modal:
- Search/select permission
- Type: Grant / Revoke
- Reason (required, logged in audit)
- Expiry date (optional — good for temporary access)

---

### Task R3: Frontend RBAC (Component-Level Permission Checks)

**The gap:** Route guards protect pages but inside a page, buttons and actions still appear regardless of permission. Users click buttons and get 403 errors instead of never seeing the button.

**What to build:**

Enhanced `usePermission` hook:
```typescript
// spa/src/hooks/usePermission.ts
export function usePermission() {
  const { permissions } = useAuthStore();

  const can = (permission: string): boolean => {
    return permissions.includes(permission) || permissions.includes('*');
  };

  const canAny = (...perms: string[]): boolean => {
    return perms.some(p => can(p));
  };

  const canAll = (...perms: string[]): boolean => {
    return perms.every(p => can(p));
  };

  return { can, canAny, canAll };
}
```

`<CanDo>` wrapper component:
```typescript
// spa/src/components/guards/CanDo.tsx
interface CanDoProps {
  permission: string | string[];
  requireAll?: boolean; // default false (any)
  fallback?: ReactNode; // what to show if no permission (default: null)
  children: ReactNode;
}

export function CanDo({ permission, requireAll = false, fallback = null, children }: CanDoProps) {
  const { can, canAny, canAll } = usePermission();
  const perms = Array.isArray(permission) ? permission : [permission];
  const hasPermission = requireAll ? canAll(...perms) : canAny(...perms);
  return hasPermission ? <>{children}</> : <>{fallback}</>;
}
```

Usage everywhere:
```tsx
// Table row action buttons
<CanDo permission="hr.employees.edit">
  <Button size="sm" onClick={() => navigate(`/hr/employees/${row.id}/edit`)}>Edit</Button>
</CanDo>

<CanDo permission="hr.employees.delete">
  <Button size="sm" variant="danger" onClick={() => setDeleteTarget(row)}>Delete</Button>
</CanDo>

// Show disabled button as fallback (user sees it's there but can't click)
<CanDo
  permission="payroll.periods.finalize"
  fallback={<Button size="sm" disabled title="You don't have permission to finalize payroll">Finalize</Button>}
>
  <Button size="sm" variant="primary" onClick={handleFinalize}>Finalize</Button>
</CanDo>

// Sidebar navigation items (already filtered, but belt+suspenders)
<CanDo permission={['accounting.bills.view', 'accounting.invoices.view']} requireAll={false}>
  <SidebarItem icon={DollarSign} label="Finance" to="/accounting" />
</CanDo>
```

**Apply `<CanDo>` to these specific places (not just routes):**
- Every table's action column (Edit, Delete, View Sensitive buttons)
- Every form's submit button (if create/edit permission may differ)
- Approve/Reject buttons on approval pages
- "Finalize", "Post", "Send" buttons on finance pages
- "Record Output", "Mark Breakdown" on production pages
- "Provision Account", "Reset Password" on HR pages
- All bulk action buttons in DataTable

---

### Task R4: Role-Based Dashboard Defaults

**The gap:** Every user sees the same dashboard. Plant Manager needs OEE and machine status. Finance needs AR aging and cash position. HR needs headcount and leave summary.

**What to build:**

`dashboard_layouts` table already exists. Seed role-based default layouts:

| Role | Default dashboard widgets |
|---|---|
| plant_manager | Production KPIs, Chain Stage Breakdown, Machine Utilization, OEE Gauges, QC Defect Pareto, Alerts, Active WOs |
| ppc_head | Production Schedule (Gantt mini), MRP Shortages, Machine Status, WO Status Breakdown, Material Reservations |
| finance_officer | Cash Position, AR Aging, AP Aging, Revenue MTD, Unpaid Invoices, Upcoming Payables |
| hr_officer | Headcount by Dept, On Leave Today, Pending Approvals, Probation Alerts, Upcoming Payroll |
| purchasing_officer | Open PRs, Open POs, Supplier Performance, Overdue Deliveries, Low Stock Alerts |
| qc_inspector | Pending Inspections, Defect Pareto, Open NCRs, Pass Rate by Product |
| warehouse_staff | Pending GRNs, Low Stock Items, Pending Material Issues, Delivery Schedule |
| employee | Payslip Summary, Leave Balance, DTR Today, Pending Requests |

When a user first logs in, copy the role's default layout to their personal layout. They can then customize it (drag/resize) and their personal layout takes precedence.

---

## SERIES X — UI/UX POLISH

*The difference between a prototype and a product is in the details.*

---

### Task X1: Keyboard Shortcuts System

**What to build:**

```typescript
// spa/src/hooks/useKeyboardShortcuts.ts
// Register global shortcuts

// Navigation shortcuts (prefix: g = go to)
useHotkeys('g h', () => navigate('/hr/employees'));          // Go to HR
useHotkeys('g p', () => navigate('/payroll/periods'));       // Go to Payroll
useHotkeys('g a', () => navigate('/accounting'));            // Go to Accounting
useHotkeys('g i', () => navigate('/inventory/items'));       // Go to Inventory
useHotkeys('g s', () => navigate('/crm/sales-orders'));      // Go to Sales
useHotkeys('g m', () => navigate('/mrp/plans'));             // Go to MRP
useHotkeys('g d', () => navigate('/dashboard'));             // Go to Dashboard

// Action shortcuts (context-sensitive)
useHotkeys('mod+k', () => openCommandPalette());            // Search
useHotkeys('mod+s', () => submitCurrentForm());             // Save form
useHotkeys('mod+shift+n', () => openCreateModal());         // New record
useHotkeys('mod+e', () => triggerExport());                 // Export
useHotkeys('mod+p', () => triggerPrint());                  // Print
useHotkeys('Escape', () => closeModalOrPanel());            // Close

// Table shortcuts (when table is focused)
useHotkeys('j', () => selectNextRow());                     // Next row
useHotkeys('k', () => selectPrevRow());                     // Prev row
useHotkeys('Enter', () => openSelectedRow());               // Open row
useHotkeys('mod+a', () => selectAllRows());                 // Select all
```

Keyboard shortcut help overlay (press `?`):
```
┌──────────────────────────────────────────────────┐
│ Keyboard Shortcuts                         [×]   │
├──────────────────┬───────────────────────────────┤
│ NAVIGATION       │ ACTIONS                       │
│ g h  → HR        │ ⌘K   → Search                 │
│ g p  → Payroll   │ ⌘S   → Save                   │
│ g a  → Accounting│ ⌘⇧N → New record             │
│ g i  → Inventory │ ⌘E   → Export                 │
│ g s  → Sales     │ ⌘P   → Print                  │
│ g m  → MRP       │ Esc  → Close                  │
│ g d  → Dashboard │                               │
├──────────────────┴───────────────────────────────┤
│ TABLE                                            │
│ j/k  → Navigate rows     ↵  → Open row          │
│ Space → Select row       ⌘A → Select all         │
└──────────────────────────────────────────────────┘
```

---

### Task X2: Smart Form Improvements

**What to build for every form:**

1. **Auto-save draft:** Forms with > 5 fields auto-save to localStorage every 30 seconds. On return, show "You have unsaved changes from [time ago]. [Restore] [Discard]"

2. **Unsaved changes guard:**
```typescript
// Prompt before navigating away if form is dirty
function useUnsavedChangesGuard(isDirty: boolean) {
  useEffect(() => {
    if (!isDirty) return;
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [isDirty]);
}
```

3. **Smart defaults:** On create forms, pre-fill from context:
   - Creating a Work Order from a Sales Order page → pre-fill product, customer context
   - Creating a Bill from a GRN page → pre-fill vendor, items, amounts
   - Creating Leave from employee detail → pre-fill employee

4. **Inline validation:** Validate on blur (not on submit only). Show green checkmark on valid fields, red error on invalid.

5. **Character counters:** On text areas with max length, show "128/500 characters".

6. **Currency input formatting:** As user types in a money field, format in real-time: typing "486500" → shows "486,500.00". Store raw value.

---

### Task X3: Better Empty States (Context-Aware)

**Replace ALL generic "No data found" with specific, helpful empty states:**

```tsx
// Each module has its own empty state that matches the context

// Employees empty state
<EmptyState
  icon={<Users size={40} strokeWidth={1} />}
  title="No employees yet"
  description="Add your first employee to start managing your workforce. Once added, they can be assigned shifts, tracked for attendance, and included in payroll."
  action={<Button variant="primary" onClick={() => navigate('/hr/employees/create')}>Add First Employee</Button>}
/>

// Search empty state (after filter applied)
<EmptyState
  icon={<SearchX size={40} strokeWidth={1} />}
  title={`No employees match "${search}"`}
  description="Try adjusting your search terms or clearing the filters."
  action={<Button variant="secondary" onClick={clearFilters}>Clear all filters</Button>}
/>

// Production empty state (no active work orders)
<EmptyState
  icon={<Factory size={40} strokeWidth={1} />}
  title="No active work orders"
  description="Work orders are created automatically when a Sales Order is confirmed and MRP planning is complete."
  action={<Button variant="secondary" onClick={() => navigate('/crm/sales-orders/create')}>Create Sales Order</Button>}
/>
```

---

### Task X4: Data Table Enhancements

**Additions to the DataTable component:**

1. **Column pinning:** Pin "Employee No" or "SO Number" column to the left so it stays visible on horizontal scroll.

2. **Row expand:** Click expand icon on a row → shows inline detail panel (saves navigation for quick lookups).

3. **Column resize:** Drag column headers to resize.

4. **Density toggle:** Toggle between Compact (28px), Default (32px), Spacious (40px) per-table per-user.

5. **Column visibility:** Per-table column visibility saved per user. "Customize columns" button → toggle which columns show.

6. **Sticky header:** Table header stays fixed while scrolling through long lists.

7. **Row selection with count:** When rows selected, show "5 selected" in the bulk action bar.

8. **Context menu (right-click):**
   - Open in new tab
   - Copy record ID
   - Quick status change (if applicable)
   - Copy row as CSV

9. **Inline editing:** For simple fields (quantity, status), click to edit inline in the table without navigating away.

---

### Task X5: Loading & Transition Polish

**Remove every remaining jarring transition:**

1. **Page transitions:** 150ms fade between routes (not instant, not bouncy).

2. **Suspense boundaries per module:** Don't show full-page loader. Show the layout shell (sidebar + topbar) immediately, then lazy-load the page content.

3. **Optimistic updates:** When toggling a status, update the UI immediately and revert if the API call fails (with error toast).

4. **Skeleton consistency:** Every possible loading state has a skeleton, not a spinner. Spinners only inside buttons.

5. **Stale data indicator:** When refetching in background (TanStack Query), show a subtle "Refreshing..." text in the page header — not a full loading overlay.

---

## SERIES F — NEW FEATURES

*Features that are standard in any professional ERP but currently missing.*

---

### Task F1: Calendar View (Cross-Module)

**What to build:**

`pages/calendar/index.tsx` — company-wide calendar showing everything:

```
May 2026
Mo Tu We Th Fr Sa Su
                  1  2  3
 4  5  6  7  8  9 10
11 12 13 14 15 16 17
18 19 20 21 22 23 24
25 26 27 28 29 30 31
```

Events shown on calendar:
- 🔵 Holidays (from holidays table)
- 🟢 Employees on leave (from approved leave_requests)
- 🟡 Scheduled deliveries (from deliveries.scheduled_date)
- 🔴 Machine maintenance (from maintenance_work_orders)
- 🟣 Payroll cutoff dates (from payroll_periods)
- ⚪ WO planned end dates (from work_orders.planned_end)

Filters: Department, Event type. Toggle layers on/off.

Click event → opens detail popup with link to record.

Month/Week/Day views. Week view shows time-based layout.

---

### Task F2: Kanban Views for Approvals

**What to build:**

`pages/approvals/index.tsx` — central approval hub for the current user.

Kanban board with columns matching approval stages:
```
Submitted       │ My Action Required  │ Awaiting Others │ Approved  │ Rejected
─────────────── │ ──────────────────  │ ──────────────  │ ─────────  │ ────────
LR-202604-0045  │ PR-202604-0018 ⚠️   │ PO-202604-0015  │ LR-0044   │ CA-0023
Leave · 3 days  │ Purchase · ₱85K     │ PO · ₱120K      │ VL · 5d   │ Loan
Ana Reyes       │ Production Dept     │ Warehouse        │ Carlos M. │ Juan C.
Apr 08-10       │ Urgent · 2 days old │ Sent Apr 06      │ Approved  │ Rejected
[Approve][Rej.] │ [Approve] [Reject]  │                 │           │ reason
```

Filter: by type (Leave / Loans / PRs / POs / Payroll). Sort: by urgency, date.

---

### Task F3: Inventory Stock Card

**What to build:**

Per-item detailed movement history — essential for warehouse management and audits.

`pages/inventory/items/[id]/stock-card.tsx`:

```
Stock Card: Resin A (RM-001)                [Export CSV] [Print]
Date Range: [Apr 2026 ▾]

Opening Balance: 250.000 kg · ₱ 120.00/kg · ₱ 30,000.00
───────────────────────────────────────────────────────────────────
Date       Ref No.         Movement         In      Out    Balance
────────── ─────────────── ──────────────── ─────── ────── ───────
Apr 02     GRN-202604-0003 GRN Receipt      500.000        750.000
Apr 03     WO-202604-0006  Material Issue           75.000  675.000
Apr 04     WO-202604-0007  Material Issue          150.000  525.000
Apr 06     GRN-202604-0005 GRN Receipt      200.000        725.000
Apr 06     ADJ-202604-0001 Stock Adjustment          5.000  720.000
────────────────────────────────────────────────────────────────────
Closing Balance: 720.000 kg · ₱ 118.50/kg · ₱ 85,320.00
```

- Weighted average cost shown per movement
- Running balance column
- Every reference is a clickable link to the source document
- Export to CSV preserves the same format

---

### Task F4: Supplier Performance Dashboard

**What to build:**

`pages/purchasing/suppliers/[id]/performance.tsx`

Key metrics per supplier over selected period:

| Metric | Calculation | Target |
|---|---|---|
| On-Time Delivery Rate | POs delivered on/before expected_delivery_date / total POs | ≥ 95% |
| Quality Pass Rate | GRNs that passed incoming QC / total GRNs | ≥ 98% |
| Price Variance | Average (actual_price - PO_price) / PO_price | ≤ 5% |
| Lead Time Accuracy | Average actual lead time vs quoted lead time | ≤ 2 days |
| Overall Score | Weighted average of above 4 | ≥ 85% |

Charts: trend line over 6 months, bar chart comparing to other suppliers for same material.

Alert: if supplier score drops below 80%, flag in Purchasing dashboard and notify Purchasing Officer.

Approved supplier list now shows performance score next to each supplier.

---

### Task F5: Employee Directory & Org Chart

**What to build:**

`pages/hr/directory/index.tsx` — visual employee directory.

```
[Search by name, position, department...]    [View: Grid | List | Org Chart]

PRODUCTION DEPARTMENT (42 employees)
┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐
│    [Photo]       │ │    [Photo]       │ │    [Photo]       │
│ Ricardo Tanaka   │ │ Roberto Santos   │ │ Manuel Cruz      │
│ Production Mgr   │ │ Production Head  │ │ Operator         │
│ Day Shift        │ │ Day Shift        │ │ Extended Shift   │
│ ● Active         │ │ ● Active         │ │ ● Active         │
│ Ext: 201         │ │ Ext: 205         │ │ Ext: —           │
└──────────────────┘ └──────────────────┘ └──────────────────┘
```

Org Chart view: hierarchical tree showing department → manager → heads → staff. Clickable nodes. Shows reporting structure.

Click employee card → slide-over panel with: photo, contact info, position, department, direct manager, current shift. Link to full employee detail page.

---

### Task F6: Bulk Operations Center

**What to build:**

Common bulk operations that currently require individual record actions:

**HR Bulk Operations:**
- Bulk shift assignment: select department → assign shift → effective date → all employees get the shift
- Bulk leave credit: at year start, bulk-add leave balances for all employees
- Bulk status update: mark selected employees as on_leave / returned (for mass events)
- Bulk salary adjustment: apply % increase to all employees in a department (with approval)

**Payroll Bulk Operations:**
- Bulk payslip email: after period finalized → send all payslips in one click
- Bulk bank file generate: combine multiple periods into one bank file

**Inventory Bulk Operations:**
- Bulk stock adjustment: adjust multiple items at once (end of physical count)
- Bulk reorder point update: set reorder points for all items in a category

**Quality Bulk Operations:**
- Bulk NCR close: close multiple resolved NCRs with same resolution note

Frontend: each list page → "Bulk Actions" dropdown in DataTable (only appears when rows selected).

---

### Task F7: System Activity Feed (Audit Dashboard)

**What to build:**

`pages/admin/activity/index.tsx` — real-time company-wide activity stream.

```
Company Activity                        [Filter] [Export]
──────────────────────────────────────────────────────────
● now    Ana Reyes finalized Payroll Period Apr 1–15
         213 employees · Net: ₱ 2,847,500.00
         [View Period]

● 2m     Rosa Villareal passed outgoing inspection
         QC-202604-0018 · WB-001 · 8,000 pcs · AQL 0.65
         [View Inspection]

● 15m    Pedro Garcia confirmed production schedule
         6 Work Orders scheduled across 4 machines
         [View Schedule]

● 1h     System auto-generated Purchase Request
         PR-202604-0022 · Resin C shortage · URGENT
         [View PR]

● 3h     Juan Bautista completed maintenance work order
         Machine IM-003 · 2h 30min downtime
         [View WO]
```

Features:
- Real-time updates (WebSocket channel: `company.activity`)
- Filter by: user, module, action type, date range
- Activity types: transactions, approvals, system automation, alerts
- Click any activity → navigates to the referenced record
- Export to CSV for audit trail

---

## EXECUTION ORDER (recommended)

```
PHASE 1 — Self-Service & Users (2 weeks)
  U1 → U2 → U3 → U4

PHASE 2 — Chain Automation (2 weeks)
  C1 → C2 → C3 → C4 → C5

PHASE 3 — RBAC (1 week)
  R1 → R2 → R3 → R4

PHASE 4 — Exports & PDFs (1 week)
  E1 → E2 → E3

PHASE 5 — UX Polish (1 week)
  X1 → X2 → X3 → X4 → X5

PHASE 6 — New Features (2 weeks)
  F1 → F2 → F3 → F4 → F5 → F6 → F7
```

**Total: ~9 weeks additional development on top of the base 8 sprints.**

---

## SUMMARY: AUTOMATION IMPACT AFTER ALL TASKS

After completing C1–C5, A1–A10, and the new automation hooks:

```
WHAT HUMANS STILL DO (irreducible):
  ✓ Confirm Sales Orders (business decision)
  ✓ Approve purchase orders above ₱50K (financial control)
  ✓ Physically receive goods (warehouse action)
  ✓ Record QC pass/fail (measurement, judgment)
  ✓ Drive and deliver (physical action)
  ✓ Record production output (supervisor floor observation)
  ✓ Review and approve payroll (financial control)
  ✓ Handle NCR corrective actions (root cause analysis)

WHAT THE SYSTEM NOW DOES AUTOMATICALLY:
  ✓ MRP planning triggered on every SO + daily refresh
  ✓ Work Orders created and materials reserved
  ✓ Capacity checked and schedule proposed
  ✓ PR created on low stock
  ✓ PO created on approved PR
  ✓ PO emailed to supplier on approval
  ✓ Incoming QC triggered on GRN
  ✓ Bill drafted on QC pass
  ✓ Outgoing QC triggered on WO completion
  ✓ Delivery drafted on QC pass
  ✓ CoC generated and attached
  ✓ Invoice drafted on delivery confirmation
  ✓ GL posted on every financial event
  ✓ Payroll period created on schedule
  ✓ Payslips generated and emailed
  ✓ Bank file generated
  ✓ NCR created on inspection failure
  ✓ Replacement WO created on NCR scrap disposition
  ✓ Approval reminders and escalations
  ✓ Preventive maintenance scheduled
  ✓ Alerts generated proactively
  ✓ Production summary emailed daily
```

**The system's job:** Do the routine. Escalate the exception. The human's job: Handle the exception.
