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

# Verify the archive before touching the live tree. A checksum-valid artifact
# can still be replaced by an operator or remote storage, so reject traversal
# paths before extraction as well.
gzip -t "${FILE}"
tar -tzf "${FILE}" >/dev/null
if tar -tzf "${FILE}" | awk '
    $0 ~ /^\// || $0 ~ /(^|\/)\.\.(\/|$)/ { bad = 1 }
    END { exit bad ? 1 : 0 }
'; then
    :
else
    echo "ERROR: private files archive contains an unsafe path" >&2
    exit 2
fi

SOURCE_PARENT="$(dirname -- "${FILES_SOURCE_DIR}")"
SOURCE_NAME="$(basename -- "${FILES_SOURCE_DIR}")"
STAGING_DIR="${SOURCE_PARENT}/.${SOURCE_NAME}.restore-staging-$$"
PREVIOUS_DIR="${SOURCE_PARENT}/.${SOURCE_NAME}.restore-previous-$$"
HAD_PREVIOUS=0

cleanup() {
    rm -rf -- "${STAGING_DIR}"
    if [ "${HAD_PREVIOUS}" -eq 1 ] && [ ! -e "${FILES_SOURCE_DIR}" ] && [ -e "${PREVIOUS_DIR}" ]; then
        mv -- "${PREVIOUS_DIR}" "${FILES_SOURCE_DIR}" || true
    fi
    rm -rf -- "${PREVIOUS_DIR}"
}
trap cleanup EXIT

mkdir -p "${STAGING_DIR}"
tar -xzf "${FILE}" \
    --directory "${STAGING_DIR}" \
    --no-same-owner \
    --no-same-permissions

# Publish with a same-filesystem directory swap. The old tree remains
# recoverable until the new tree is visible.
if [ -e "${FILES_SOURCE_DIR}" ]; then
    mv -- "${FILES_SOURCE_DIR}" "${PREVIOUS_DIR}"
    HAD_PREVIOUS=1
fi
if ! mv -- "${STAGING_DIR}" "${FILES_SOURCE_DIR}"; then
    echo "ERROR: could not publish the restored private files tree" >&2
    exit 1
fi
rm -rf -- "${PREVIOUS_DIR}"
HAD_PREVIOUS=0

echo "private files restore complete."
