#!/usr/bin/env bash
# Rebuilds /audit/00-MODULE-REGISTRY.md as a generated view by scanning every
# module's status.md. This file is never edited directly by any session -
# it's always derived, so there's nothing for parallel sessions to clobber.

set -e
# Paths resolve against the audit/ dir this script lives in, so the tree works
# from any cwd and is not tied to a filesystem-root /audit mount.
AUDIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT="${AUDIT_DIR}/domains"
OUT="${AUDIT_DIR}/00-MODULE-REGISTRY.md"

{
  echo "# Module Registry"
  echo ""
  echo "_Generated $(date -u +"%Y-%m-%d %H:%M UTC") by scripts/regenerate-registry.sh - do not edit by hand, edit each module's status.md instead._"
  echo ""
  echo "| ID | Domain | Module | Tier | Roles | Depends On | Status | Last Session | Locked? |"
  echo "|----|--------|--------|------|-------|-----------|--------|---------------|---------|"
} > "$OUT"

find "$ROOT" -mindepth 2 -maxdepth 2 -type d 2>/dev/null | sort | while read -r moddir; do
  status_file="${moddir}/status.md"
  [ -f "$status_file" ] || continue

  domain=$(basename "$(dirname "$moddir")")
  module=$(basename "$moddir")
  id=$(grep '^id:'          "$status_file" | cut -d' ' -f2-)
  tier=$(grep '^tier:'      "$status_file" | cut -d' ' -f2-)
  roles=$(grep '^roles:'    "$status_file" | cut -d' ' -f2-)
  depends=$(grep '^depends_on:' "$status_file" | cut -d' ' -f2-)
  status=$(grep '^status:'  "$status_file" | cut -d' ' -f2-)
  last=$(grep '^last_session:' "$status_file" | cut -d' ' -f2-)
  locked="no"
  [ -d "${moddir}/.lock" ] && locked="🔒 yes"

  echo "| ${id} | ${domain} | ${module} | ${tier} | ${roles} | ${depends} | ${status} | ${last} | ${locked} |" >> "$OUT"
done

echo "" >> "$OUT"
echo "Registry regenerated." 
