# OGAMI ERP — Claude Code Master Context

> Claude Code reads this automatically on every command. Read completely before executing any task.
> References: `docs/DESIGN-SYSTEM.md`, `docs/TASKS.md`, `docs/SCHEMA.md`, `docs/SEEDS.md`

## PROJECT

Production-grade ERP for **Philippine Ogami Corporation** — Japanese-owned plastic injection molding manufacturer (200+ employees, FCIE Dasmariñas, Cavite). Makes wiper bushings, pivot caps, relay covers for Toyota, Nissan, Honda, Suzuki, Yamaha. IATF 16949 certified. Thesis project, 8 months, solo developer.

## THE THREE CHAINS (this is what matters)

Every feature serves one of three end-to-end business processes. When building, always ask: "which chain does this serve?"

```
CHAIN 1 — ORDER TO CASH
  CRM Sales Order → MRP Plan → MRP II Schedule → Work Order → QC (in-process) →
  Finished Goods → QC (outgoing AQL) → Delivery → Customer Confirm → Invoice → Collection → GL

CHAIN 2 — PROCURE TO PAY
  Material Shortage (MRP) → Purchase Request → Approval → PO → Supplier →
  Shipment (ImpEx) → Receive (GRN) → QC (incoming) → Inventory → Bill → Payment → GL

CHAIN 3 — HIRE TO RETIRE
  Hire → Profile → Shift Assignment → Biometric CSV → DTR Computation →
  Leave/OT Approvals → Payroll → Payslip → Bank File → GL → Separation → Clearance → Final Pay
```

## IATF 16949 QUALITY (woven through every chain, not a separate module)

Quality is the thesis differentiator. Four touchpoints:

1. **Incoming QC** (Chain 2, after GRN) — verify resin certs, moisture before accepting inventory
2. **In-process QC** (Chain 1, during production) — periodic sampling between operations
3. **Outgoing QC** (Chain 1, before delivery) — AQL 0.65 Level II sampling, measurements vs spec tolerances
4. **NCR feedback loop** (any chain) — failure creates corrective action work order; replacement WO auto-generated; defect data flows to Pareto analysis

Every product has **inspection specs** (dimensions + tolerances). Every inspection records **actual measurements**. Every failure becomes a traceable **NCR**. Every shipment gets a **Certificate of Conformance** auto-generated from inspection data.

## ARCHITECTURE

```
React 18 SPA (Vite + TypeScript) ←HTTP-only cookies→ Laravel 11 REST API (PHP 8.3)
                                              │
              PostgreSQL 16 · Redis 7 · Meilisearch · Laravel Reverb (WebSocket)
```

Fully decoupled. API at `/api/v1/*`, SPA at `/*`, WebSocket at `/ws`. Docker Compose.

## MODULES (12)

| # | Module | Chains |
|---|---|---|
| 1 | HR (employees, departments, positions, separation, clearance) | 3 |
| 2 | Attendance (shifts, DTR, OT, biometric import) | 3 |
| 3 | Leave (types, balances, approval workflow) | 3 |
| 4 | Payroll (engine, gov deductions, payslip, bank file, 13th month) | 3 |
| 5 | Loans (company loan + cash advance, auto-deduction) | 3 |
| 6 | Accounting (COA, JE, AP, AR, VAT, financial statements) | all |
| 7 | Inventory (items, warehouse, GRN, issue, stock) | 1, 2 |
| 8 | Purchasing (PR, PO, approval, 3-way match) | 2 |
| 9 | Supply Chain (shipments, import docs, fleet, delivery) | 1, 2 |
| 10 | Production (work orders, output, machine downtime, OEE) | 1 |
| 11 | MRP / MRP II (BOM, material planning, capacity, Gantt, molds) | 1, 2 |
| 12 | CRM (customers, price agreements, sales orders, complaints, 8D) | 1 |

Plus: **Quality** (specs, inspections, NCR, CoC at 4 chain touchpoints, not a module), **Maintenance** (machine breakdowns, mold shot tracking, preventive schedules), **Dashboard** (live KPIs, chain stage breakdown, alerts, Pareto).

## NOT BUILDING (cut scope — scope discipline ships this thesis)

- ❌ Finance module (budgets, cost accounting, cash flow forecasts)
- ❌ Bank reconciliation, budget approval/transfers, closing wizards, fiscal period locking
- ❌ Tax compliance calendar
- ❌ Customizable dashboards (react-grid-layout), saved views scheduling, automation rule builder UI
- ❌ Setup wizard, guided tours, onboarding system, announcements, changelog, feedback form
- ❌ System health monitoring dashboard
- ❌ Import center with mapping/preview (simple CSV upload is enough)
- ❌ Activity feeds on every record (only on SO, PO, WO, NCR)
- ❌ RFQ process, per-shot mold depreciation

## SECURITY (production-grade, mandatory)

### Authentication: Sanctum SPA mode with HTTP-only cookies

**NEVER use Bearer tokens. NEVER store auth in localStorage/sessionStorage.**

```
1. SPA → GET /sanctum/csrf-cookie → receives XSRF-TOKEN cookie
2. SPA → POST /api/v1/auth/login (credentials + X-XSRF-TOKEN header)
3. Server validates → creates session → sets HTTP-only session cookie
4. All subsequent requests carry session cookie automatically
5. JavaScript cannot read HTTP-only cookies → immune to XSS token theft
```

```php
// config/cors.php
'supports_credentials' => true,
'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

// config/sanctum.php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,localhost:3000')),

// config/session.php
'driver' => 'database',
'lifetime' => 30,
'secure' => env('SESSION_SECURE_COOKIE', true),
'http_only' => true,
'same_site' => 'lax',
```

```typescript
// spa/src/api/client.ts
const client = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,  // MANDATORY
  headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

// Before login:
await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
await client.post('/auth/login', { email, password });
```

### URL ID Obfuscation (HashIDs)

**NEVER expose integer IDs in URLs or API responses.**

```php
// composer require vinkla/hashids
// app/Common/Traits/HasHashId.php
trait HasHashId {
    public function resolveRouteBinding($value, $field = null) {
        $decoded = app('hashids')->decode($value);
        if (empty($decoded)) abort(404);
        return $this->where('id', $decoded[0])->firstOrFail();
    }
    public function getHashIdAttribute(): string {
        return app('hashids')->encode($this->id);
    }
}

// Every model uses HasHashId
// Every API Resource returns hash_id, NEVER raw id:
public function toArray($request) {
    return ['id' => $this->hash_id, /* ... */];  // 'yR3kLm' not 42
}
```

### Route Guards (3 layers on frontend)

```typescript
// 1. AuthGuard — redirects to /login if no session
// 2. ModuleGuard — shows "module disabled" if feature toggle off
// 3. PermissionGuard — shows 403 if user lacks permission

<Route element={<AuthGuard><AppLayout /></AuthGuard>}>
  <Route element={<ModuleGuard module="production"><Outlet /></ModuleGuard>}>
    <Route element={<PermissionGuard permission="production.wo.view"><Outlet /></PermissionGuard>}>
      <Route path="/production/work-orders" element={<WorkOrderList />} />
    </Route>
  </Route>
</Route>
```

**Backend enforces permissions independently via middleware.** Frontend guards are UX only.

### Security Headers (Nginx)

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self' ws: wss:;" always;
```

### Rate Limiting & Account Protection

```php
RateLimiter::for('auth', fn($r) => Limit::perMinute(5)->by($r->ip()));
RateLimiter::for('api', fn($r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
RateLimiter::for('sensitive', fn($r) => Limit::perMinute(10)->by($r->user()?->id));

// Account lockout: 5 failed logins → lock 15 min
// Password expiry: 90 days (forced change on next login)
// Password history: cannot reuse last 3
// Policy: min 8 + uppercase + number + special
// bcrypt cost: 12
```

### Data Protection

```php
// Encrypted at rest:
protected $casts = [
    'sss_no' => 'encrypted',
    'philhealth_no' => 'encrypted',
    'pagibig_no' => 'encrypted',
    'tin' => 'encrypted',
    'bank_account_no' => 'encrypted',
];

// Data masking in API Resources (non-HR users see "***-**-4567")
// Row-level filtering ALWAYS server-side (never trust frontend)
```

### Other rules

- Every controller action checks permissions via middleware OR `FormRequest::authorize()`
- Every financial operation wrapped in `DB::transaction()`
- `SanitizeInput` middleware strips tags on all string inputs
- File uploads: validate MIME server-side, random filenames, stored outside web root, served via controller with permission check
- `APP_DEBUG=false` in production, generic errors to client
- Never use `DB::raw()` with user input
- Session timeout: Employee 15 min, others 30 min
- All auth + financial events logged with IP + user agent

## FILE STRUCTURE

```
ogami-erp/
├── CLAUDE.md
├── docker-compose.yml
├── docker/ (php/, nginx/, node/)
├── Makefile
├── api/                                    # Laravel 11
│   ├── app/
│   │   ├── Modules/                        # Modular Monolith
│   │   │   ├── Auth/ HR/ Attendance/ Leave/ Payroll/ Loans/
│   │   │   ├── Accounting/ Inventory/ Purchasing/ SupplyChain/
│   │   │   ├── Production/ MRP/ CRM/ Quality/ Maintenance/ Dashboard/
│   │   │   └── (each: Controllers/ Models/ Services/ Requests/ Resources/ Jobs/ routes.php)
│   │   ├── Common/
│   │   │   ├── Traits/ (HasHashId, HasAuditLog, HasApprovalWorkflow)
│   │   │   ├── Services/ (ApprovalService, DocumentSequenceService, NotificationService)
│   │   │   ├── Enums/
│   │   │   └── Middleware/
│   │   └── Providers/
│   ├── database/migrations/ (numbered: 0001_, 0002_, ...)
│   ├── database/seeders/
│   ├── resources/views/pdf/                # DomPDF Blade templates
│   ├── routes/api.php
│   └── tests/
└── spa/                                    # React 18 + TypeScript + Vite
    ├── src/
    │   ├── api/                            # Per-module Axios functions
    │   ├── components/
    │   │   ├── ui/                         # Base primitives
    │   │   ├── chain/                      # ChainHeader, StageBreakdown, LinkedRecords
    │   │   └── layout/                     # Sidebar, Topbar, PageHeader
    │   ├── hooks/
    │   ├── layouts/                        # AppLayout, AuthLayout, SelfServiceLayout
    │   ├── pages/                          # Grouped by module
    │   ├── stores/                         # Zustand (auth, theme, sidebar)
    │   ├── types/
    │   ├── lib/
    │   └── styles/tokens.css               # CSS variables for design system
    └── tailwind.config.ts
```

## URL ROUTING CONVENTION (Modular Monolith)

The SPA mirrors the modular-monolith backend structure. Every employee-facing
business module lives under a top-level path that names its umbrella module:

```
/hr/employees           Sprint 2 — HR / People
/hr/departments
/hr/positions
/hr/attendance          Sprint 2 — HR / Attendance subdomain
/hr/attendance/import
/hr/attendance/shifts
/hr/attendance/holidays
/hr/attendance/overtime
/hr/leaves              Sprint 2 — HR / Leaves
/hr/loans               Sprint 2 — HR / Loans

/payroll/periods        Sprint 3 — HR / Payroll (kept under /payroll for clarity)

/inventory/...          Operations modules — own top-level prefix
/purchasing/...
/supply-chain/...
/production/...
/mrp/...
/crm/...
/quality/...
/maintenance/...
/accounting/...

/admin/settings         Cross-cutting admin
/admin/roles
/admin/audit-logs
```

**Rules**

1. Every URL must start with the umbrella module slug (`/hr`, `/inventory`, …).
   No bare resource paths like `/leaves` or `/loans` — they collide as the
   product grows and obscure ownership in audit logs.
2. Sub-resources nest under the parent module, e.g. `/hr/attendance/overtime`,
   not `/overtime`. The sidebar's longest-prefix match in
   [`Sidebar`](spa/src/components/layout/Sidebar.tsx:1) relies on this so only
   the most specific item lights up.
3. The filesystem layout under `spa/src/pages/` does NOT have to mirror the
   URL exactly — it follows the React component tree (e.g. `pages/attendance/`
   is fine even though the URL is `/hr/attendance`). Keep imports stable.
4. Backend API paths (`/api/v1/leaves/requests`, etc.) are independent of the
   SPA route prefix — do not rename them when restructuring SPA URLs.

## CODE CONVENTIONS

### PHP / Laravel

```php
declare(strict_types=1);

// Enums for ALL status/type fields
enum EmployeeStatus: string {
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Resigned = 'resigned';
    case Terminated = 'terminated';
}

// Models: fillable, casts, relationships, traits
class Employee extends Model {
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog;

    protected $fillable = [/* ... */];

    protected $casts = [
        'basic_monthly_salary' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'status' => EmployeeStatus::class,
        'date_hired' => 'date',
        'sss_no' => 'encrypted',
        'tin' => 'encrypted',
        'bank_account_no' => 'encrypted',
    ];

    public function department(): BelongsTo {
        return $this->belongsTo(Department::class);
    }
}

// Controllers: thin — delegate to Services
class EmployeeController extends Controller {
    public function __construct(private EmployeeService $service) {}

    public function index(ListEmployeesRequest $request): AnonymousResourceCollection {
        return EmployeeResource::collection($this->service->list($request->validated()));
    }

    public function store(StoreEmployeeRequest $request): EmployeeResource {
        return new EmployeeResource($this->service->create($request->validated()));
    }
}

// Services: ALL business logic
class EmployeeService {
    public function __construct(private DocumentSequenceService $sequences) {}

    public function create(array $data): Employee {
        return DB::transaction(function () use ($data) {
            $data['employee_no'] = $this->sequences->generate('employee');
            return Employee::create($data);
        });
    }
}

// Form Requests: validation + authorization
class StoreEmployeeRequest extends FormRequest {
    public function authorize(): bool {
        return $this->user()->can('hr.employees.create');
    }
    public function rules(): array {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'basic_monthly_salary' => ['nullable', 'decimal:0,2', 'min:0'],
        ];
    }
}

// API Resources: shape JSON, mask sensitive, return hash_id
class EmployeeResource extends JsonResource {
    public function toArray($request): array {
        return [
            'id' => $this->hash_id,
            'employee_no' => $this->employee_no,
            'full_name' => $this->full_name,
            'sss_no' => $this->maskIfNotAuthorized($this->sss_no, $request->user(), 'hr.employees.view_sensitive'),
            'department' => new DepartmentResource($this->whenLoaded('department')),
        ];
    }
}
```

### TypeScript / React

```typescript
// Types match Laravel models
interface Employee {
  id: string;  // hash_id, always string
  employee_no: string;
  first_name: string;
  last_name: string;
  full_name: string;
  status: 'active' | 'on_leave' | 'resigned' | 'terminated';
  basic_monthly_salary: string;  // decimals come as strings
  department: Department;
}

// API layer per module
export const employeesApi = {
  list: (params?: ListParams) => client.get<Paginated<Employee>>('/employees', { params }),
  show: (id: string) => client.get<{ data: Employee }>(`/employees/${id}`),
  create: (data: CreateEmployeeData) => client.post<{ data: Employee }>('/employees', data),
  update: (id: string, data: UpdateEmployeeData) => client.put<{ data: Employee }>(`/employees/${id}`, data),
  delete: (id: string) => client.delete(`/employees/${id}`),
};

// Pages use TanStack Query
export default function EmployeeList() {
  const { data, isLoading } = useQuery({
    queryKey: ['employees', filters],
    queryFn: () => employeesApi.list(filters).then(r => r.data),
  });
}

// Forms use React Hook Form + Zod
const schema = z.object({
  first_name: z.string().min(1).max(100),
  basic_monthly_salary: z.string().regex(/^\d+\.?\d{0,2}$/),
});
```

### Database Conventions

- PKs: `id` bigint auto-increment (exposed as HashIDs)
- Money: `decimal(15, 2)` — **NEVER float**
- FKs: `{table_singular}_id`
- Soft deletes: employees, vendors, customers, users, items, machines, molds, products
- Sensitive fields: Laravel `encrypted` cast
- Migrations numbered: `0001_`, `0002_`, ...

### Number formats (monthly reset, `document_sequences` table)

```
Employee       OGM-YYYY-NNNN     OGM-2026-0142
Purchase Order PO-YYYYMM-NNNN    PO-202604-0015
Invoice        INV-YYYYMM-NNNN   INV-202604-0008
Journal Entry  JE-YYYYMM-NNNN    JE-202604-0032
Work Order     WO-YYYYMM-NNNN    WO-202604-0006
NCR            NCR-YYYYMM-NNNN   NCR-202604-0002
GRN            GRN-YYYYMM-NNNN   GRN-202604-0011
Sales Order    SO-YYYYMM-NNNN    SO-202604-0003
Leave Request  LR-YYYYMM-NNNN    LR-202604-0045
Inspection     QC-YYYYMM-NNNN    QC-202604-0012
```

## KEY BUSINESS RULES (quick reference)

- **Currency:** Philippine Peso only (₱)
- **Payroll:** Semi-monthly. Gov deductions on 1st period only
- **Pay types:** Monthly salaried AND daily-rated (both supported)
- **OT:** Min 30min, Max 4hrs. Extended shift (6AM–6PM) = auto-OT
- **Night diff:** 10% premium for 10PM–6AM ONLY
- **Loans:** Zero interest. Max 1 month salary. 1 loan + 1 CA at a time
- **Inventory valuation:** Weighted average cost
- **Outgoing QC:** AQL 0.65 Level II. Actual measurements for critical dimensions
- **Approval chain:** Staff → Dept Head → Manager → Officer → VP (4 levels)
- **Mold shot count:** Auto-increment. Alert at 80% of max
- **Weighted avg cost:** Recalculated on every purchase receipt
- **Payroll corrections:** Never unlock finalized. Adjustment in next period

## DESIGN SYSTEM QUICK REFERENCE

Full spec in `docs/DESIGN-SYSTEM.md`. Summary:

- **Font:** Geist (sans) + Geist Mono (numbers, IDs, tables)
- **Canvas:** Pure grayscale. Zero color in backgrounds, text, borders, sidebars
- **6 accent colors only:** Indigo (primary), Emerald (success), Amber (warning), Red (danger), Blue (info), Purple (optional)
- **Applied only to:** Primary buttons, status chips, KPI deltas, alert dots, links
- **Tables:** 32px rows, monospace for numbers (tabular figures)
- **Sidebar:** Collapsible (240px ↔ 56px rail)
- **Radius:** 6px everywhere
- **Animations:** Minimal — loading, progress, status changes only
- **Dark mode:** First-class

## TASK EXECUTION PROTOCOL

### MANDATORY: Before writing ANY code, read `docs/PATTERNS.md`

PATTERNS.md contains exact, copy-paste code templates for every page type, every component usage, every API call, and every error state. **DO NOT improvise structure.** Find the matching pattern, copy it, change entity names and fields. This is how we prevent sloppy code.

### Steps for every task:

1. Read this file (CLAUDE.md) — already happening
2. **Read `docs/PATTERNS.md`** — find the matching template (list page, form page, detail page, controller, service, etc.)
3. Read `docs/TASKS.md`, find the task
4. Read relevant sections of `docs/SCHEMA.md` for table specs
5. Read `docs/DESIGN-SYSTEM.md` if task involves UI
6. Read `docs/SEEDS.md` if task involves seed data
7. **Copy the matching pattern from PATTERNS.md and adapt it** — do NOT write from scratch
8. Pattern for full module: Migration → Enum → Model → Service → Request → Resource → Controller → Routes → Types → API → Pages → Components

### Rules (NEVER violate):

- Every model gets `HasHashId` trait
- Every API Resource returns `hash_id`, never raw integer `id`
- Every financial operation wrapped in `DB::transaction()`
- Every list page handles 5 states: loading (skeleton), error (retry), empty (message), data (table), stale (placeholder)
- Every form has: Zod schema matching backend, submit button disabled while pending, loading text, server-side error mapping, cancel button
- Every mutation has: toast.success on success, toast.error on failure, queryClient.invalidateQueries
- Every page is lazy-loaded with `React.lazy()`
- Every route wrapped in `AuthGuard` + `ModuleGuard` + `PermissionGuard`
- Numbers always use `font-mono tabular-nums`
- Status fields always use `<Chip>` with semantic variant mapping
- Never use Bearer tokens — HTTP-only cookies only
- Never store auth in localStorage/sessionStorage
- Git commit after each task: `feat: task N — description`

### Final checklist (run mentally before finishing any task):

See the complete checklist at the bottom of `docs/PATTERNS.md`. Every checkbox must pass.
