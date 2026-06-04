# Ogami ERP — k6 Load Tests

Performance and concurrency tests for the Ogami ERP API using [k6](https://k6.io/).

---

## Installation

```bash
# macOS
brew install k6

# Debian / Ubuntu
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
     --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
     | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update && sudo apt-get install k6

# Windows (Chocolatey)
choco install k6

# Docker (no install required)
docker run --rm -i grafana/k6 run - <script.js
```

---

## Running the tests

All scripts are run from the **repository root** (`/path/to/ogami-erp/`).

### Against the local Docker stack (default)

```bash
# Inventory concurrency (200 VUs, 60 s)
k6 run api/tests/Load/concurrent-inventory.js

# Payroll serialization (10 VUs, 120 s)
k6 run api/tests/Load/concurrent-payroll.js
```

### Against a specific environment

Pass `BASE_URL`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD` as environment variables:

```bash
BASE_URL=http://localhost \
ADMIN_EMAIL=admin@ogami.test \
ADMIN_PASSWORD=Password1! \
k6 run api/tests/Load/concurrent-inventory.js
```

### Overriding VU count or duration at runtime

```bash
# Run inventory test with only 50 VUs for a quick smoke check
k6 run --vus 50 --duration 30s api/tests/Load/concurrent-inventory.js
```

---

## Scripts

### `concurrent-inventory.js`

| Parameter | Value |
|-----------|-------|
| Virtual Users | 200 |
| Duration | 60 s |
| p95 threshold | < 2 000 ms |
| Error rate threshold | < 1 % |

Simulates 200 warehouse users simultaneously querying inventory. Each VU:
1. Authenticates via Sanctum SPA cookies
2. Loops 5 times per iteration:
   - `GET /api/v1/inventory/stock-levels?page=1&per_page=25`
   - `GET /api/v1/inventory/items?search=<random-code>`
   - Sleeps 1–3 s between requests

**Purpose:** Verify the inventory read path (stock levels + item search) stays
under 2 s at the 95th percentile when all warehouse staff are active at once.

---

### `concurrent-payroll.js`

| Parameter | Value |
|-----------|-------|
| Virtual Users | 10 |
| Duration | 120 s |
| p95 threshold | < 5 000 ms |
| Error rate threshold | < 1 % |

Simulates 10 concurrent users accessing the payroll module. The low VU count
is intentional — payroll endpoints acquire DB row locks and run heavy
computations. The test verifies:

- No `500 Internal Server Error` responses under concurrent access
- Proper serialization: all responses are `200 OK` or `403 Forbidden`
- Response bodies are valid JSON with the expected pagination structure
- Optionally drills into the most recent payroll period detail

**Purpose:** Catch serialization bugs (missing `DB::transaction()` wrappers,
race conditions on period status transitions) before they surface in production.

---

## Authentication

Both scripts use the `getAuthCookie()` helper in `config.js`, which replicates
the Sanctum SPA authentication flow:

1. `GET /sanctum/csrf-cookie` — receives `XSRF-TOKEN` cookie
2. `POST /api/v1/auth/login` — sends credentials + `X-XSRF-TOKEN` header
3. Returns the `laravel_session` cookie string for use in subsequent requests

**No Bearer tokens are used.** This matches the production security model
(HTTP-only cookies, no tokens in `localStorage`).

---

## Reading the output

k6 prints a summary at the end of each run:

```
✓ stock-levels status 200
✓ stock-levels response time < 2000ms
...

http_req_duration............: avg=312ms  min=89ms   med=278ms  max=1843ms p(90)=589ms p(95)=742ms
http_req_failed..............: 0.00%  ✓ 0    ✗ 12000
```

A run **passes** when all threshold lines show a checkmark (✓).
A run **fails** when any threshold line shows a cross (✗) — investigate the
slowest endpoints in the `http_req_duration` breakdown.
