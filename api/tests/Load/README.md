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

Run from the repository root against the isolated test app at
`http://localhost:18080` by default. Set `BASE_URL` to another dedicated test
app when needed; do not point these tests at a user or production app.

Seed the isolated test database first, from its API container:

```bash
AUDIT_E2E_TESTING=true php artisan db:seed --class='Database\Seeders\LoadTestUserSeeder'
```

The seeder refuses production and any database whose name does not start with
`ogami_test_`. It creates 200 warehouse actors and 10 system-admin actors with
the test-only password `password`.

### Against the isolated audit app (default)

```bash
# Inventory concurrency (200 VUs, 60 s)
k6 run api/tests/Load/concurrent-inventory.js

# Payroll reads (10 VUs, 120 s)
k6 run api/tests/Load/concurrent-payroll.js
```

### Against a specific environment

Override `BASE_URL` only for another isolated test app:

```bash
BASE_URL=http://localhost:18080 \
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

Simulates 200 concurrent inventory reads with 50 seeded warehouse actors. Setup
authenticates each actor once using Sanctum SPA cookies, then assigns four VUs
per actor at the default load:
1. VUs share only read-only sessions; each actor stays below the 300-request/minute authenticated cap
2. Loops 5 times per iteration:
   - `GET /api/v1/inventory/stock-levels?page=1&per_page=25`
   - `GET /api/v1/inventory/items?search=<random-code>`
   - Sleeps 1–3 s between requests

**Purpose:** Verify the inventory read path (stock levels + item search) stays
under 2 s at the 95th percentile under concurrent reads without exceeding
either the 60-request/minute guest login cap or any actor's authenticated API
rate limit.

---

### `concurrent-payroll.js`

| Parameter | Value |
|-----------|-------|
| Virtual Users | 10 |
| Duration | 120 s |
| p95 threshold | < 5 000 ms |
| Error rate threshold | < 1 % |

Simulates 10 concurrent payroll reads with one seeded system-admin account per
VU. Setup authenticates each actor once and shares its read-only session only
with that VU. The test verifies:

- No `500 Internal Server Error` responses under concurrent access
- No server errors: responses are `200 OK` or `403 Forbidden`
- Response bodies are valid JSON with the expected pagination structure
- Optionally drills into the most recent payroll period detail

**Purpose:** Verify payroll list and detail reads stay under 5 s at the 95th
percentile without server errors.

---

## Authentication

Both scripts use the `getAuthCookie()` helper in `config.js`, which replicates
the Sanctum SPA authentication flow:

1. `GET /sanctum/csrf-cookie` — receives `XSRF-TOKEN` cookie
2. `POST /api/v1/auth/login` — sends credentials, `Origin`, `Referer`, and `X-XSRF-TOKEN`
3. Returns the session cookies from k6's cookie jar for subsequent requests

**No real credentials or Bearer tokens are used.** This matches the production security model
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
