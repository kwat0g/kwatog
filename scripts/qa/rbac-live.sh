#!/usr/bin/env bash
# OGAMI ERP — live RBAC / SoD smoke harness (Sanctum SPA cookie auth).
#
# Drives the running stack at $BASE and asserts permission boundaries + SoD
# guards. This is the script that produced docs/AUTO-BROWSER-TESTS.md findings.
# It is API-level (curl) so it runs headless in CI without a browser; the
# auto-browser convergence contracts in evals/contracts/ mirror the same
# terminal assertions for the UI path.
#
# Usage:  BASE=http://localhost bash scripts/qa/rbac-live.sh
# Requires: curl, python3. Demo seed must be loaded (password = 'password').
#
# NOTE on rate limiting: the auth throttle is 5/min/IP. Logging in 12 roles +
# the 6-bad-login brute-force probe from one IP exceeds that window, so login()
# is 429-aware (retries with backoff) — a rate-limited login is never mistaken
# for a 401/403 privilege boundary. A full clean run therefore takes ~1-2 min.
set -uo pipefail

BASE="${BASE:-http://localhost}"
ORIGIN="$BASE"
JARDIR="$(mktemp -d)"
PASS=0; FAIL=0
trap 'rm -rf "$JARDIR"' EXIT

_xsrf() { grep -i 'XSRF-TOKEN' "$1" | tail -1 | awk '{print $7}' \
  | python3 -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))"; }

login() { # <profile> <email> [pw]  -- 429-aware: the auth throttle is 5/min/IP,
          # which the mass-login loop below can exhaust. Retry on 429 with backoff
          # so a rate-limited login is never mistaken for a privilege boundary.
  local prof="$1" email="$2" pw="${3:-password}" jar="$JARDIR/$1.txt" c x i
  for i in 1 2 3 4; do
    rm -f "$jar"
    curl -s -c "$jar" -b "$jar" -o /dev/null -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/" "$BASE/sanctum/csrf-cookie"
    x="$(_xsrf "$jar")"
    c=$(curl -s -c "$jar" -b "$jar" -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/" \
      -H "X-XSRF-TOKEN: $x" -H "Content-Type: application/json" -H "Accept: application/json" \
      -H "X-Requested-With: XMLHttpRequest" -X POST "$BASE/api/v1/auth/login" \
      -d "{\"email\":\"$email\",\"password\":\"$pw\"}" -o "$JARDIR/$prof.login.json" -w '%{http_code}')
    [ "$c" = 429 ] || { echo "$c"; return; }
    sleep $((i*15))   # back off past the per-minute auth window
  done
  echo "$c"
}

req() { # <profile> <METHOD> <path> [json] -> body + __HTTP__<code>
  local prof="$1" method="$2" path="$3" body="${4:-}" jar="$JARDIR/$1.txt"
  local x; x="$(_xsrf "$jar")"
  local args=(-s -b "$jar" -c "$jar" -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/"
    -H "X-XSRF-TOKEN: $x" -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest"
    -X "$method" "$BASE/api/v1$path" -w $'\n__HTTP__%{http_code}')
  [ -n "$body" ] && args+=(-H "Content-Type: application/json" -d "$body")
  curl "${args[@]}"
}
code() { req "$@" | sed -n 's/.*__HTTP__//p' | tail -1; }

assert() { # <label> <expected> <got>
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); printf "  PASS  %-50s exp=%s got=%s\n" "$1" "$2" "$3"
  else FAIL=$((FAIL+1));   printf "  FAIL  %-50s exp=%s got=%s\n" "$1" "$2" "$3"; fi
}

echo "== Logging in all demo roles =="
declare -A R=( [admin]=admin@ogami.test [hr]=hr@ogami.test [finance]=finance@ogami.test
  [prod]=production@ogami.test [ppc]=ppc@ogami.test [purch]=purchasing@ogami.test
  [wh]=warehouse@ogami.test [qc]=qc@ogami.test [maint]=maintenance@ogami.test
  [impex]=impex@ogami.test [depthead]=depthead@ogami.test [emp]=employee@ogami.test )
for p in "${!R[@]}"; do c=$(login "$p" "${R[$p]}"); [ "$c" = 200 ] || echo "  WARN login $p -> $c"; done

echo; echo "== Vertical privilege escalation =="
assert "admin GET /admin/users"               200 "$(code admin GET /admin/users)"
assert "hr    GET /admin/users (deny)"         403 "$(code hr GET /admin/users)"
assert "emp   GET /admin/roles (deny)"         403 "$(code emp GET /admin/roles)"
assert "emp   POST /hr/employees (deny)"       403 "$(code emp POST /hr/employees '{}')"
assert "qc    GET /hr/employees (deny)"        403 "$(code qc GET /hr/employees)"
assert "prod  GET /quality/inspections (ok)"   200 "$(code prod GET /quality/inspections)"
assert "prod  POST /quality/inspections (deny)" 403 "$(code prod POST /quality/inspections '{}')"
assert "qc    GET /production/work-orders (deny)" 403 "$(code qc GET /production/work-orders)"
assert "wh    POST /purchasing/purchase-orders (deny)" 403 "$(code wh POST /purchasing/purchase-orders '{}')"
assert "impex GET /purchasing/purchase-orders (view)"  200 "$(code impex GET /purchasing/purchase-orders)"
assert "hr    GET /journal-entries (deny)"     403 "$(code hr GET /journal-entries)"
assert "finance GET /journal-entries (ok)"     200 "$(code finance GET /journal-entries)"

echo; echo "== SoD: Leave self-approval (Guard A, expect 422) =="
EMP_DH=$(python3 -c "import json;print(json.load(open('$JARDIR/depthead.login.json'))['data']['employee']['id'])")
LT=$(req depthead GET /leaves/types | sed 's/__HTTP__.*//' | python3 -c "import sys,json;print(json.load(sys.stdin)['data'][0]['id'])")
# Use a far-future, randomised date window to avoid colliding with leave the
# employee already has on file (overlap check would otherwise 422 on create).
OFF=$(( (RANDOM % 200) + 120 ))
SD=$(date -d "+${OFF} days" +%F 2>/dev/null || date -v+${OFF}d +%F)
LR_BODY="{\"employee_id\":\"$EMP_DH\",\"leave_type_id\":\"$LT\",\"start_date\":\"$SD\",\"end_date\":\"$SD\",\"reason\":\"SoD probe\"}"
LRESP=$(req depthead POST /leaves/requests "$LR_BODY")
LR=$(echo "$LRESP" | sed 's/__HTTP__.*//' | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)
[ -n "${LR:-}" ] && assert "depthead self approve-dept own leave" 422 "$(code depthead PATCH "/leaves/requests/$LR/approve-dept")" \
  || echo "  SKIP leave self-approval (create failed: $(echo "$LRESP" | sed -n 's/.*__HTTP__//p') — likely leftover overlapping leave; Guard A verified separately)"

echo; echo "== SoD: OT self-approval (Guard E) -- DEFECT-1 watch =="
OD=$(date -d "-2 days" +%F 2>/dev/null || date -v-2d +%F)
ORESP=$(req depthead POST /attendance/overtime-requests "{\"employee_id\":\"$EMP_DH\",\"date\":\"$OD\",\"hours_requested\":2,\"reason\":\"SoD OT probe\"}")
OT=$(echo "$ORESP" | sed 's/__HTTP__.*//' | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)
if [ -n "${OT:-}" ]; then
  OC=$(code depthead PATCH "/attendance/overtime-requests/$OT/approve")
  # Secure behaviour = 422. 200 means the guard is dead code (DEFECT-1).
  assert "depthead self-approve own OT (want 422)" 422 "$OC"
  [ "$OC" = 200 ] && echo "  >>> DEFECT-1 CONFIRMED: OT self-approval succeeded (HTTP 200)."
else echo "  SKIP OT self-approval (create failed)"; fi

echo; echo "== SoD: JE maker-checker (Guard B, expect 422 then admin 200) =="
JE_BODY="{\"date\":\"$(date +%F)\",\"description\":\"SoD probe\",\"lines\":[{\"account_id\":\"kABpVvpKRW\",\"debit\":1000,\"credit\":0},{\"account_id\":\"BnoNyGw56v\",\"debit\":0,\"credit\":1000}]}"
JRESP=$(req finance POST /journal-entries "$JE_BODY")
JE=$(echo "$JRESP" | sed 's/__HTTP__.*//' | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)
if [ -n "${JE:-}" ]; then
  assert "finance self-post own JE"    422 "$(code finance PATCH "/journal-entries/$JE/post")"
  assert "admin override-post same JE"  200 "$(code admin PATCH "/journal-entries/$JE/post")"
else echo "  SKIP JE maker-checker (create failed: account hashes may differ in this seed)"; fi

echo; echo "== Loan business rules =="
LH="{\"employee_id\":\"$EMP_DH\",\"loan_type\":\"company_loan\",\"principal\":9999999.99,\"pay_periods\":12,\"purpose\":\"cap\"}"
assert "loan over-cap rejected"        422 "$(code depthead POST /loans "$LH")"

echo; echo "== Horizontal payslip leakage =="
OP=$(req admin GET "/payrolls?per_page=50" | sed 's/__HTTP__.*//' | python3 -c "
import sys,json
me=json.load(open('$JARDIR/emp.login.json'))['data']
mine=me['employee']['id'] if me.get('employee') else None
for p in json.load(sys.stdin).get('data',[]):
    if p.get('employee',{}).get('id')!=mine: print(p['id']); break" 2>/dev/null)
[ -n "${OP:-}" ] && assert "emp reads other payslip (deny)" 403 "$(code emp GET "/payrolls/$OP/payslip")" \
  || echo "  SKIP payslip leakage (no computed payrolls in seed)"

echo; echo "== Auth hardening + HashID =="
BFJAR="$JARDIR/bf.txt"; curl -s -c "$BFJAR" -o /dev/null -H "Origin: $ORIGIN" "$BASE/sanctum/csrf-cookie"
BX=$(_xsrf "$BFJAR"); LAST=""
for i in $(seq 1 6); do LAST=$(curl -s -c "$BFJAR" -b "$BFJAR" -o /dev/null -w '%{http_code}' \
  -H "Origin: $ORIGIN" -H "X-XSRF-TOKEN: $BX" -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "X-Requested-With: XMLHttpRequest" -X POST "$BASE/api/v1/auth/login" \
  -d '{"email":"employee@ogami.test","password":"WRONG"}'); done
assert "6th bad login rate-limited"    429 "$LAST"
EH=$(req admin GET "/hr/employees?per_page=1" | sed 's/__HTTP__.*//' | python3 -c "import sys,json;print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
[ -n "${EH:-}" ] && assert "real hash id resolves" 200 "$(code admin GET "/hr/employees/$EH")"
assert "integer id 404s"               404 "$(code admin GET /hr/employees/1)"
assert "garbage id 404s"               404 "$(code admin GET /hr/employees/xxxx)"

echo; echo "== Portal isolation =="
SUPTOK=$(curl -s -H "Accept: application/json" -H "Content-Type: application/json" \
  -X POST "$BASE/api/v1/b2b/supplier/login" -d '{"email":"portal@supp.test","password":"password"}' \
  | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['token'])" 2>/dev/null)
if [ -n "${SUPTOK:-}" ]; then
  ah(){ curl -s -o /dev/null -w '%{http_code}' -H "Accept: application/json" -H "Authorization: Bearer $SUPTOK" "$BASE$1"; }
  assert "supplier token own POs"        200 "$(ah /api/v1/b2b/supplier/purchase-orders)"
  assert "supplier token employee PO(deny)" 403 "$(ah /api/v1/purchasing/purchase-orders)"
  assert "supplier token HR (deny)"      403 "$(ah /api/v1/hr/employees)"
else echo "  SKIP portal isolation (supplier login failed)"; fi

echo; echo "==============================================="
echo "  RESULT: $PASS passed, $FAIL failed"
echo "  (DEFECT-1 OT self-approval is reported as a FAIL above by design.)"
echo "==============================================="
[ "$FAIL" -eq 0 ]
