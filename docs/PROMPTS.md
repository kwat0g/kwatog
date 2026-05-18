P=========ROMPT 1: SESSION START (run this first, every time):
    Read CLAUDE.md completely. Then read docs/PATTERNS.md completely. Then read docs/DESIGN-SYSTEM.md completely.

    Confirm you have read all three by listing:
    1. The three chain processes (Chain 1, 2, 3)
    2. The authentication method (NOT Bearer tokens)
    3. The ID obfuscation method
    4. The five mandatory page states
    5. The canvas color rule (when is color allowed?)
    6. The table row height and number formatting rule

    Do not write any code yet. Just confirm you have read and understood everything.


========PROMPT 2: TASK EXECUTION (use this for every task):
    You are building the Ogami ERP system. Your instruction files are:
- CLAUDE.md (project rules, security, conventions — already read)
- docs/PATTERNS.md (copy-paste code templates — already read)
- docs/DESIGN-SYSTEM.md (visual spec — already read)
- docs/ADVISER-TASKS.md (task queue)
- docs/SCHEMA.md (database schemas)
- docs/SEEDS.md (seed data)

Execute Task ADV2, ADV3, and ADV4 from docs/ADVISER-TASKS.md.

Before writing any code:
1. Read the task description in docs/ADVISER-TASKS.md
2. Read every relevant table from docs/SCHEMA.md for this task
3. Identify the matching pattern(s) in docs/PATTERNS.md (migration, model, service, controller, list page, form, etc.)
4. Plan what files you will create/modify and in what order

Then execute in this exact order:
BACKEND FIRST:
  → Migration (follow Migration Pattern in PATTERNS.md exactly)
  → Enum (PHP 8.1 backed enum, one per status/type field)
  → Model (follow Model Pattern — HasHashId, HasAuditLog, encrypted casts, relationships)
  → Service (follow Service Pattern — DB::transaction, eager loading, pagination)
  → FormRequest (follow Form Request Pattern — authorize() checks permission, exhaustive rules)
  → API Resource (follow Resource Pattern — hash_id only, mask sensitive fields)
  → Controller (follow Controller Pattern — thin, delegates to service, correct HTTP codes)
  → Routes (follow Route Pattern — auth:sanctum + feature + permission middleware)

FRONTEND SECOND:
  → TypeScript types (interfaces matching the API Resource output exactly)
  → API layer (follow API Layer Pattern — all methods, hash_id strings)
  → Pages (follow List/Detail/Form patterns from PATTERNS.md — ALL 5 states mandatory)
  → Route registration in App.tsx (lazy import + AuthGuard + ModuleGuard + PermissionGuard)

MANDATORY RULES (verify each before finishing):
  ✓ Every model has HasHashId trait
  ✓ Every API Resource returns hash_id, never raw integer id
  ✓ Every financial operation wrapped in DB::transaction()
  ✓ Every list page has: skeleton (loading), empty state (no data), error state (failed), data table (success)
  ✓ Every form has: Zod schema, submit button disabled while pending, server-side error mapping, cancel button, success/error toast
  ✓ Every route has: AuthGuard + ModuleGuard + PermissionGuard
  ✓ Every number in tables uses font-mono tabular-nums
  ✓ Every status field uses <Chip> with semantic variant (success/warning/danger/info/neutral)
  ✓ No color on canvas, backgrounds, or text — color only on chips, buttons, alerts, deltas
  ✓ No Bearer tokens, no localStorage for auth
  ✓ Geist font for text, Geist Mono for all numbers/IDs/dates
  ✓ Table rows: 32px height, uppercase letter-spaced headers

After completing the task, list every file you created or modified.


=====THE AUDIT PROMPT:
    You have just implemented Tasks [START] to [END]. Before we move to the next sprint, 
perform a complete audit of everything you built. Do not skip any step.

Read these files completely before starting the audit:
- CLAUDE.md (all rules and conventions)
- docs/PATTERNS.md (all code patterns and the final checklist)
- docs/DESIGN-SYSTEM.md (all visual rules)
- docs/ADVISER-TASKS.md (tasks [START] to [END] — verify scope was fully covered)
- docs/SCHEMA.md (relevant tables — verify schema was implemented correctly)

═══════════════════════════════════════════════════════
PHASE 1: SCOPE CHECK
═══════════════════════════════════════════════════════

For each task from [START] to [END], answer YES or NO:

Task [N]: [task name]
  → Was this task fully implemented? (YES/NO)
  → List every file created or modified for this task
  → If NO or PARTIAL — explain what is missing

Do this for every task in the range. If anything is missing, 
implement it now before continuing to Phase 2.

═══════════════════════════════════════════════════════
PHASE 2: BACKEND AUDIT
═══════════════════════════════════════════════════════

For every Model created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] Has HasHashId trait?
  [ ] Has HasAuditLog trait?
  [ ] Has SoftDeletes (if required by SCHEMA.md)?
  [ ] All money fields cast as 'decimal:2'?
  [ ] All sensitive fields (sss_no, tin, bank_account_no, philhealth_no, pagibig_no) cast as 'encrypted'?
  [ ] All relationships defined (belongsTo, hasMany, etc.)?
  [ ] $fillable array is complete (no missing columns)?
  [ ] Enums used for all status/type fields (never raw strings)?

For every Migration created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] All money fields are decimal(15,2)? (no float, no integer)
  [ ] All foreign keys use ->constrained()? (not just ->unsignedBigInteger())
  [ ] Indexes added on: all FK columns, status columns, date columns used in filters?
  [ ] Sensitive fields stored as TEXT (not VARCHAR — encryption expands data)?
  [ ] SoftDeletes added where SCHEMA.md specifies deleted_at?
  [ ] Numbered prefix matches sequence (no gaps, no duplicates)?

For every Service created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] list() method has eager loading with ->with([]) to prevent N+1?
  [ ] list() method always paginates (never returns unbounded results)?
  [ ] list() method has search, sort, and all relevant filters?
  [ ] create() method wrapped in DB::transaction()?
  [ ] update() method wrapped in DB::transaction()?
  [ ] create() generates document number via DocumentSequenceService if needed?

For every FormRequest created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] authorize() method checks the correct permission (not just returns true)?
  [ ] rules() validates EVERY field (no fields accepted without rules)?
  [ ] Conditional rules used where needed (required_if, nullable)?
  [ ] exists: validation on all foreign key fields?
  [ ] Rule::in() used for all enum fields?
  [ ] Custom messages() method with user-friendly error text?

For every API Resource created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] Returns 'id' => $this->hash_id (NEVER $this->id)?
  [ ] Sensitive fields go through maskField() or equivalent?
  [ ] Relationships use $this->whenLoaded() (not direct access)?
  [ ] Money fields returned as strings (decimal cast preserves precision)?
  [ ] Dates formatted as 'Y-m-d' strings?

For every Controller created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] index() returns ResourceCollection (paginated)?
  [ ] store() returns 201 status code?
  [ ] destroy() returns 204 status code (no body)?
  [ ] Controller is thin — all logic delegated to Service?
  [ ] No business logic directly in controller methods?

For every Route file created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] All routes under auth:sanctum middleware?
  [ ] All routes under feature:{module} middleware?
  [ ] Every individual route has permission middleware?
  [ ] Route model binding resolves via HashID (not integer)?

═══════════════════════════════════════════════════════
PHASE 3: FRONTEND AUDIT
═══════════════════════════════════════════════════════

For every List Page created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] LOADING STATE: Shows <SkeletonTable /> when isLoading and no cached data?
  [ ] ERROR STATE: Shows <EmptyState> with retry button when isError?
  [ ] EMPTY STATE: Shows <EmptyState> with contextual message when data.length === 0?
  [ ] DATA STATE: Shows <DataTable> when data exists?
  [ ] STALE STATE: Uses placeholderData on useQuery to prevent flash?
  [ ] Numbers in table cells use className="font-mono tabular-nums"?
  [ ] Status fields use <Chip variant="..."> with correct variant mapping?
  [ ] Permission check before showing Create/Edit/Delete buttons?
  [ ] Filters reset page to 1 when changed?
  [ ] Page is exported as default export (for lazy loading)?

For every Form Page created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] Zod schema matches FormRequest rules exactly (same fields, same constraints)?
  [ ] useMutation onSuccess: invalidates query cache?
  [ ] useMutation onSuccess: shows toast.success with descriptive message?
  [ ] useMutation onSuccess: navigates to correct page?
  [ ] useMutation onError (422): maps server errors to specific fields via setError()?
  [ ] useMutation onError (422): shows toast.error('Please fix the errors below.')?
  [ ] Submit button has disabled={isSubmitting || mutation.isPending}?
  [ ] Submit button shows loading text ('Creating...' / 'Saving...') while pending?
  [ ] Cancel button navigates back without saving?
  [ ] Money/currency inputs have className="font-mono" and ₱ prefix?
  [ ] Required fields have required prop on Input component?
  [ ] Field errors shown inline via error={errors.field?.message}?

For every Detail Page created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] Shows <SkeletonDetail /> while loading?
  [ ] Shows error state if fetch fails?
  [ ] Uses <PageHeader> with title, status chip, and action buttons?
  [ ] If it is a chain record (SO, PO, WO, NCR, Leave): has <ChainHeader> with all steps?
  [ ] If it is a chain record: has <LinkedRecords> panel showing related records?
  [ ] Edit button only shown if user has edit permission?
  [ ] Delete button only shown if user has delete permission?

For every API file created in tasks [START]–[END]:
  Read the actual file. Then answer:
  [ ] All IDs passed as strings (hash_ids), never numbers?
  [ ] All methods return .then(r => r.data) or .then(r => r.data.data)?
  [ ] Client uses withCredentials: true (inherited from base client)?

For every Route registration in App.tsx:
  Read the actual route block. Then answer:
  [ ] Page is lazy imported with React.lazy()?
  [ ] Wrapped in <AuthGuard>?
  [ ] Wrapped in <ModuleGuard module="...">?
  [ ] Wrapped in <PermissionGuard permission="...">?

═══════════════════════════════════════════════════════
PHASE 4: DESIGN SYSTEM AUDIT
═══════════════════════════════════════════════════════

Open each page that has a UI in tasks [START]–[END] and check:

  [ ] Canvas/background colors are CSS variables from tokens.css (--bg-canvas, --bg-surface)?
  [ ] NO hardcoded hex colors anywhere in the components?
  [ ] Color appears ONLY on: primary buttons, status chips, alert dots, KPI deltas, links?
  [ ] Table row height is 32px (h-8 in Tailwind)?
  [ ] Table column headers use: text-2xs uppercase tracking-wider text-muted font-medium?
  [ ] Table numeric columns: text-right font-mono tabular-nums?
  [ ] Border radius is 6px (rounded-md) consistently?
  [ ] Borders are 0.5px using border-default (from CSS variable)?
  [ ] Font weights used: only 400 (normal) and 500 (medium)? No 600, 700, or bold?
  [ ] Geist font loaded and applied to body?
  [ ] Geist Mono applied to all: amounts, quantities, IDs, dates, document numbers?
  [ ] Dark mode: does the page look correct in dark mode (all colors use CSS variables)?
  [ ] No drop shadows on cards or buttons (only --shadow-menu on dropdowns)?
  [ ] No bounce/spring animations — only 100–200ms linear/ease transitions?

═══════════════════════════════════════════════════════
PHASE 5: CHAIN PROCESS AUDIT
═══════════════════════════════════════════════════════

For every record that belongs to a chain:

Order-to-Cash records (CRM SO, Work Order, QC Inspection, Delivery, Invoice):
  [ ] Detail page has <ChainHeader> showing: Order Entered → MRP Planned → 
      In Production → QC Outgoing → Delivered → Invoiced → Collected
  [ ] Current step is marked as 'active', completed steps as 'done', future as 'pending'
  [ ] <LinkedRecords> panel shows all related records across the chain

Procure-to-Pay records (PR, PO, GRN, Bill, Payment):
  [ ] Detail page has <ChainHeader> showing: Shortage Detected → PR Created → 
      PO Approved → Supplier Notified → GRN Received → QC Incoming → 
      Bill Created → Payment Made
  [ ] <LinkedRecords> panel shows related records

Hire-to-Retire records (Employee, Attendance, Leave, Payroll):
  [ ] Employee detail page shows tabs linking to: Attendance, Payroll, Leaves, Loans, Documents
  [ ] Payroll period detail page links to individual payrolls and GL journal entry

═══════════════════════════════════════════════════════
PHASE 6: SECURITY AUDIT
═══════════════════════════════════════════════════════

  [ ] No route returns raw integer IDs anywhere in the JSON response?
  [ ] No component reads from localStorage or sessionStorage for auth?
  [ ] No Axios calls use Authorization: Bearer headers?
  [ ] All Axios calls use withCredentials: true?
  [ ] All sensitive data (SSS, TIN, bank accounts) is masked for non-authorized users?
  [ ] Employee can only see their own data in self-service routes?
  [ ] FormRequest authorize() methods check real permissions (not just return true)?

═══════════════════════════════════════════════════════
AUDIT RESULT
═══════════════════════════════════════════════════════

After completing all 6 phases, report:

PASSED: [list every check that passed]

FAILED: [list every check that failed with the exact file and line]

FIXED: [list every issue you found and fixed during this audit]

STILL BROKEN: [list anything you could not fix automatically — 
               explain why and what manual action is needed]

READY FOR NEXT SPRINT: YES / NO

If NO — fix everything in FAILED before declaring done.
If YES — we can proceed to Task [END + 1].