# auto-browser convergence contracts — OGAMI ERP

Task-contract JSON files for the [`auto-browser`](https://github.com/LvcidPsyche/auto-browser)
convergence harness. Each contract encodes one test's **terminal assertion** so the
harness can verify autonomously without a human reading `/observe`.

Run one:

```bash
python -m controller.harness.run \
  --contract evals/contracts/02_leave_sod_self_approve.json \
  --auth-profile P_DEPTHEAD
```

(For local dry-runs the harness accepts `--mock-final-url` / `--mock-final-text`
to inject the expected end state, as in the upstream `example_read.json`.)

## Contract shape (mirrors upstream `example_read.json`)

```jsonc
{
  "id": "...",
  "goal": "...",
  "precondition":  { "start_url": "..." },
  "postconditions": {
    "url_contains":  "...",          // required
    "text_contains": "...",          // optional — the assertion string
    "http_status":   403             // OGAMI extension: API-path tests
  },
  "forbidden_states": ["captcha", "payment", "login_redirect"],
  "evidence":  { "trace": true, "actions": true, "screenshots": true },
  "budget":    { "steps": 8, "attempts": 1, "wall_clock_s": 120, "model_calls": 12, "usd": 0.50 },
  "auth_profile": "P_DEPTHEAD"       // the saved login profile to open from
}
```

## Index

| File | Test | Expected terminal assertion |
|---|---|---|
| `01_leave_maker_checker.json` | TEST 1 | leave reaches `approved` after dept+HR |
| `02_leave_sod_self_approve.json` | TEST 2 | "You cannot act on a record you submitted." |
| `03_ot_sod_self_approve.json` | TEST 3 | **want** "cannot approve your own overtime" (🔴 DEFECT-1: currently approves) |
| `05_payslip_horizontal_leak.json` | TEST 5 | 403 on another employee's payslip |
| `10_je_maker_checker.json` | TEST 10 | "...journal entry you created...segregation of duties." |
| `13_admin_surface_rbac.json` | TEST 13 | non-admin → 403 on `/admin/users` |
| `15_hashid_obfuscation.json` | TEST 15 | integer id → 404 |

See `../../docs/AUTO-BROWSER-TESTS.md` for the full matrix and live results.
