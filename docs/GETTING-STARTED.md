# OGAMI ERP — Getting Started

## YOUR 5 FILES

You have 5 files that form a complete AI-executable blueprint:

```
ogami-erp/
├── CLAUDE.md                    ← Claude Code reads this AUTOMATICALLY
└── docs/
    ├── DESIGN-SYSTEM.md         ← exact visual spec (colors, components, layouts)
    ├── TASKS.md                 ← 85 sequential build tasks
    ├── SCHEMA.md                ← every database table/column/type
    └── SEEDS.md                 ← all seed data (gov tables, holidays, COA, demo)
```

**File hierarchy:**
- `CLAUDE.md` — the master context. Claude Code loads it on every command. Contains: project overview, 3 chains, security architecture, file structure, code conventions, business rules.
- `docs/TASKS.md` — the build queue. 85 ordered tasks. You execute them sequentially.
- `docs/DESIGN-SYSTEM.md` — referenced by UI tasks. Has every color token, component pattern, layout rule.
- `docs/SCHEMA.md` — referenced by backend tasks. Every table specified column-by-column.
- `docs/SEEDS.md` — referenced by seeder tasks. SSS rates, holidays, COA, demo accounts.

---

## STEP 1: INSTALL CLAUDE CODE

```bash
# Install (requires Node.js 18+)
npm install -g @anthropic-ai/claude-code

# Verify
claude --version

# Login
claude login
```

## STEP 2: SET UP PROJECT DIRECTORY

```bash
mkdir ogami-erp
cd ogami-erp
git init
mkdir docs

# Place the 5 files:
# CLAUDE.md            → ogami-erp/CLAUDE.md
# DESIGN-SYSTEM.md     → ogami-erp/docs/DESIGN-SYSTEM.md
# TASKS.md             → ogami-erp/docs/TASKS.md
# SCHEMA.md            → ogami-erp/docs/SCHEMA.md
# SEEDS.md             → ogami-erp/docs/SEEDS.md

# First commit
git add .
git commit -m "docs: project specification and AI build instructions"
```

## STEP 3: USE SONNET 4.6 (NOT OPUS)

Configure Claude Code to use Sonnet 4.6 — it's faster, more efficient, and perfect for code generation from a clear spec. Opus is overkill and will burn your token budget.

```bash
# Set Sonnet as default model
claude config set model claude-sonnet-4-6
```

## STEP 4: START BUILDING

```bash
# Task 1 — Docker Compose
claude "Read docs/TASKS.md. Execute Task 1 completely. Follow CLAUDE.md conventions."

# Verify
docker compose up -d
docker compose ps  # all services should be running

# Commit
git add .
git commit -m "feat: task 1 — docker compose setup"

# Task 2 — Laravel scaffolding
claude "Execute Task 2 from docs/TASKS.md."

# Continue through all 85 tasks...
```

---

## DAILY WORKFLOW

```
Morning:
  1. cd ogami-erp
  2. docker compose up -d (if not running)
  3. code . (open VS Code)
  4. Check which task you're on (last commit message tells you)
  5. claude "Execute task N from docs/TASKS.md"
  6. Review generated code
  7. Test in browser at http://localhost
  8. If good: git add . && git commit -m "feat: task N — description"
  9. If broken: claude "Task N failed: [paste error]. Fix it."
  10. Move to next task

Evening:
  git push origin main
```

---

## TIPS FOR CLAUDE CODE

### DO:
- Execute ONE task at a time
- Reference docs in your prompts: "Reference SCHEMA.md for the employees table"
- Share exact error messages
- Commit after each successful task
- Run `make fresh` weekly to catch migration ordering bugs
- Take screenshots as you build (you'll need them for the thesis)

### DON'T:
- Don't say "build the whole module" — tasks are already sized correctly
- Don't skip tasks — they have dependencies
- Don't modify CLAUDE.md mid-project — consistency matters
- Don't store auth tokens in localStorage (CLAUDE.md forbids this)
- Don't use Bearer tokens (CLAUDE.md mandates HTTP-only cookies)
- Don't expose integer IDs in URLs (CLAUDE.md mandates HashIDs)

### When Claude Code makes mistakes:

```bash
# Be specific:
claude "The EmployeeController returns 500 on POST /api/v1/employees.
Error from docker logs: [paste exact error].
Reference api/app/Modules/HR/. Fix following CLAUDE.md conventions."

# Or give it the relevant files to read:
claude "Read api/app/Modules/HR/Services/EmployeeService.php
and api/app/Modules/HR/Controllers/EmployeeController.php.
The duplicate detection isn't working. Fix it per Task 14 spec."
```

---

## USEFUL COMMANDS

```bash
# Docker
make up             # start all containers
make down           # stop all
make logs           # tail API logs
make shell          # bash into API container
make tinker         # Laravel Tinker REPL

# Database
make migrate        # run pending migrations
make seed           # run seeders
make fresh          # drop all + re-migrate + re-seed (DESTRUCTIVE)

# Testing
make test           # PHPUnit (backend)
cd spa && npm test  # Vitest (frontend)

# Frontend dev
cd spa && npm run dev    # Vite dev server with HMR
cd spa && npm run build  # production build
```

---

## ESTIMATED PACE

| Sprint | Tasks | Weeks | Pace |
|---|---|---|---|
| Sprint 1 — Foundation | 1–12 | 4 | 1 task per 1–2 days |
| Sprint 2 — HR/Attendance/Leave | 13–22 | 4 | 1 task per day |
| Sprint 3 — Payroll | 23–30 | 4 | 1 task per 2–3 days (complex) |
| Sprint 4 — Accounting | 31–38 | 4 | 1 task per day (Semester 1 ends) |
| Sprint 5 — Inventory/Purchasing | 39–46 | 4 | 1 task per day |
| Sprint 6 — Production/MRP | 47–58 | 4 | 1 task per 1–2 days (Gantt is tricky) |
| Sprint 7 — Quality/Delivery | 59–68 | 4 | 1 task per day |
| Sprint 8 — Polish/DSS/Defense | 69–85 | 4 | 1 task per day |

**Total: ~85 tasks over 32 weeks (8 months).** Some tasks are quick (2 hours), some take a full day (Payroll engine, MRP, Gantt). Plan for 6 productive days a week with rest. The plan accounts for this pace.

---

## REFERENCE THE MOCKUPS

When building UI tasks, reference the visual mockups I showed you in our planning conversation:
- **Order-to-Cash Workspace** — shows ChainHeader, dense line items table, LinkedRecords sidebar, ActivityStream
- **Plant Manager Dashboard** — shows StatCards, StageBreakdown, Alerts panel, Machine Utilization table, Defect Pareto

Both use:
- Pure grayscale canvas
- Geist font + Geist Mono for numbers
- 32px table rows with monospace tabular figures
- Status chips as the only color (success emerald / warning amber / danger red / info blue)
- Indigo (#4F46E5) primary buttons only
- Dark mode is first-class

---

## RISK MITIGATION

| Risk | Plan |
|---|---|
| Falling behind | Cut DSS polish in Sprint 8. Cut Gantt drag-drop in Sprint 6 (use simple table). Never cut: HR, Payroll, Accounting, Production, MRP, QC. |
| Payroll computation bugs | Write unit tests for SSS, PhilHealth, Pag-IBIG, BIR services BEFORE integrating into PayrollCalculatorService. Compare against official calculators. |
| Live demo breaks during defense | Always have database backup ready (`make backup`). Practice 5+ times. Record video as fallback. |
| 85 migrations get out of order | Run `make fresh` weekly to verify. Number prefixes (0001_, 0002_, ...) prevent conflicts. |
| Solo burnout | Take Sundays off. Don't skip exercise. The plan accounts for normal pace, not crunch. |

---

## WHAT MAKES THIS THESIS DEFENSIBLE

When your panel asks "what makes this different from a generic ERP?":

1. **Three end-to-end chain processes** (Order-to-Cash, Procure-to-Pay, Hire-to-Retire) with full visibility — not just modules glued together
2. **IATF 16949 quality woven throughout** — incoming inspection, in-process, outgoing AQL, NCR feedback loop. Every product has spec, every inspection records measurements, every batch traces back.
3. **MRP II with Gantt chart** — capacity planning considering machines + molds + labor + setup time
4. **Real Philippine labor law compliance** — semi-monthly payroll, all 14 holiday combinations, night differential, monster edge cases (487% rate scenarios), pro-rated salaries, daily and monthly rated employees
5. **Production-grade security** — HTTP-only cookies (no XSS token theft), HashIDs (no enumeration), 3-layer route guards, encrypted sensitive fields, account lockout, password expiry
6. **Dense professional UI** — looks like real enterprise software (SAP/Linear inspired) not a generic AI-generated webapp

These are the "wow" points. Memorize them. Lead with the chain processes.

---

## YOU ARE READY

Stop reading. Open the terminal. Execute Task 1.

```bash
mkdir ogami-erp && cd ogami-erp
git init
mkdir docs
# (place the 5 files)
git add . && git commit -m "docs: project specification"
claude "Execute Task 1 from docs/TASKS.md"
```

Build something Ogami will actually use.
