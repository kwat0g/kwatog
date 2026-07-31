#!/usr/bin/env python3
"""
Cross-check every SPA API call path against the real Laravel route table.

A mismatch here is a guaranteed 404/405 the moment a user clicks that screen —
invisible to tsc, invisible to backend tests.
"""
import json
import os
import re
import sys

API_DIR = "spa/src/api"
ROUTES_JSON = "/tmp/routes.json"
PREFIX = "api/v1"

# ---- load routes -------------------------------------------------------
routes = json.load(open(ROUTES_JSON))


def norm(uri: str) -> str:
    """Normalize a URI to method-agnostic shape: params collapse to {p}."""
    uri = uri.strip("/")
    if uri.startswith(PREFIX + "/"):
        uri = uri[len(PREFIX) + 1 :]
    elif uri == PREFIX:
        uri = ""
    # {foo} and {foo?} -> {p}
    uri = re.sub(r"\{[^}]+\}", "{p}", uri)
    return uri


# route table: (method, normalized-uri) and set of normalized uris
route_pairs = set()
route_uris = {}
for r in routes:
    n = norm(r["uri"])
    for m in r["method"].split("|"):
        if m in ("HEAD",):
            continue
        route_pairs.add((m, n))
    route_uris.setdefault(n, set()).update(
        m for m in r["method"].split("|") if m != "HEAD"
    )

# ---- scan SPA api files ------------------------------------------------
call_re = re.compile(
    r"client\.(get|post|put|patch|delete)\s*(?:<.*?>)?\s*\(\s*(`[^`]*`|'[^']*'|\"[^\"]*\")",
    re.S,
)
const_re = re.compile(
    r"^\s*const\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(`[^`]*`|'[^']*'|\"[^\"]*\")", re.M
)

problems = []
checked = 0
dynamic = []

for root, _dirs, files in os.walk(API_DIR):
    for fn in sorted(files):
        if not fn.endswith((".ts", ".tsx")):
            continue
        path = os.path.join(root, fn)
        src = open(path).read()

        consts = {}
        for m in const_re.finditer(src):
            consts[m.group(1)] = m.group(2)[1:-1]

        for m in call_re.finditer(src):
            method = m.group(1).upper()
            raw = m.group(2)[1:-1]
            line = src[: m.start()].count("\n") + 1

            # resolve ${CONST} where CONST is a known literal string const
            def sub_const(mm):
                inner = mm.group(1).strip()
                if inner in consts:
                    return consts[inner]
                return "{p}"

            resolved = re.sub(r"\$\{([^}]*)\}", sub_const, raw)

            if "{p}" in resolved and re.search(r"\$\{", raw) is None:
                pass  # literal braces, unlikely
            # strip query string
            resolved = resolved.split("?")[0]
            u = norm(resolved)
            if u == "":
                continue
            checked += 1

            if (method, u) in route_pairs:
                continue
            if u in route_uris:
                problems.append(
                    f"METHOD_MISMATCH {path}:{line}  {method} /{u}  "
                    f"— route exists but allows {sorted(route_uris[u])}"
                )
            else:
                problems.append(f"NO_SUCH_ROUTE  {path}:{line}  {method} /{u}  (raw: {raw})")

print(f"Checked {checked} SPA API call sites against {len(routes)} routes")
if not problems:
    print("\n=== 0 SPA/route mismatches ===")
    sys.exit(0)
print(f"\n=== {len(problems)} problems ===")
for p in problems:
    print("  " + p)
sys.exit(1)
