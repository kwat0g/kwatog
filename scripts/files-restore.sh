#!/usr/bin/env bash
# Restore private application uploads from a server-generated tar.gz archive.
# The caller must validate that FILE points inside the configured backup folder.

set -euo pipefail

FILE="${1:-}"
: "${FILES_SOURCE_DIR:?FILES_SOURCE_DIR required}"

if [ -z "${FILE}" ] || [ ! -f "${FILE}" ]; then
    echo "ERROR: files archive not found" >&2
    exit 2
fi
if [ -z "${FILES_SOURCE_DIR}" ] || [ "${FILES_SOURCE_DIR}" = "/" ]; then
    echo "ERROR: refusing to restore into an empty or root files directory" >&2
    exit 2
fi

gzip -t "${FILE}"
tar -tzf "${FILE}" >/dev/null
mkdir -p "${FILES_SOURCE_DIR}"

# The archive is produced by files-backup.sh. Remove the current private tree
# first so a restore is an exact snapshot rather than a merge with stale files.
find "${FILES_SOURCE_DIR}" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
tar -xzf "${FILE}" \
    --directory "${FILES_SOURCE_DIR}" \
    --no-same-owner \
    --no-same-permissions

echo "private files restore complete."
