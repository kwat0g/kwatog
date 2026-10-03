#!/usr/bin/env bash
set -euo pipefail

: "${E2E_BASE_URL:?set E2E_BASE_URL to the test Nginx base URL}"
: "${NGINX_CONTAINER:?set NGINX_CONTAINER to the test Nginx container name}"

audit_marker="audit-log-probe-$(date +%s)-$$"
audit_token="audit-query-token-$(date +%s)-$$"
status_code="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --get --data-urlencode "token=$audit_token" "${E2E_BASE_URL%/}/$audit_marker")"

if [[ ! "$status_code" =~ ^[23][0-9]{2}$ ]]; then
    printf 'Expected a 2xx or 3xx response from probe, got %s.\n' "$status_code" >&2
    exit 1
fi

server_log="$(docker logs --since=2m "$NGINX_CONTAINER" 2>&1)"
if ! grep -Fq "$audit_marker" <<< "$server_log"; then
    printf 'Nginx access log did not capture test request.\n' >&2
    exit 1
fi

if grep -Fq "$audit_token" <<< "$server_log"; then
    printf 'Nginx access log exposed query token.\n' >&2
    exit 1
fi

printf 'Nginx logged probe URI without query token.\n'
