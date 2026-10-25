#!/usr/bin/env bash
# adapt-skill.sh - Convert a Claude-Code-style SKILL.md into a Roo-compatible skill file.
#
# Usage: scripts/adapt-skill.sh <source-SKILL.md> <vendor-name> <upstream-sha> <output.md>
#
# What it does:
#   1. Prepends a Roo provenance header (source repo + commit SHA + license note)
#   2. Rewrites Claude Code tool references to Roo equivalents
#   3. Notes any unsupported references inline
#
# This is a low-risk text rewrite: substitutions are scoped to backtick-wrapped
# tool names so prose mentioning the words "Read" or "Edit" in normal sentences
# is left alone.

set -euo pipefail

if [[ $# -lt 4 ]]; then
  echo "Usage: $0 <source-SKILL.md> <vendor-name> <upstream-sha> <output.md>" >&2
  exit 64
fi

SRC="$1"
VENDOR="$2"
SHA="$3"
OUT="$4"

if [[ ! -f "$SRC" ]]; then
  echo "adapt-skill: source not found: $SRC" >&2
  exit 66
fi

mkdir -p "$(dirname "$OUT")"

# Derive a short skill identifier from the parent directory of the source file.
SKILL_DIR="$(basename "$(dirname "$SRC")")"

# Map upstream owner from vendor name.
case "$VENDOR" in
  superpowers) OWNER="obra" ;;
  ruflo)       OWNER="ruvnet" ;;
  *)           OWNER="unknown" ;;
esac

{
  cat <<HEADER
<!--
Source: ${OWNER}/${VENDOR} @ ${SHA}
Asset: skills/${SKILL_DIR}/SKILL.md
License: MIT (see vendor/${VENDOR}/LICENSE)
Adapted for Roo: tool names rewritten (Read->read_file, Edit->edit, Bash->execute_command, etc.).
DO NOT EDIT BY HAND - regenerate via scripts/sync-${VENDOR}.sh.
-->

HEADER

  # Stream-rewrite the source. Only substitute backtick-wrapped tool names,
  # to avoid clobbering ordinary English prose that uses the same words.
  sed -E \
    -e 's/`Read`/`read_file`/g' \
    -e 's/`Edit`/`edit`/g' \
    -e 's/`MultiEdit`/`edit` (replace_all)/g' \
    -e 's/`Write`/`write_to_file`/g' \
    -e 's/`Bash`/`execute_command`/g' \
    -e 's/`Grep`/`search_files`/g' \
    -e 's/`Glob`/`list_files`/g' \
    -e 's/`Task`/`new_task` (or orchestrator mode)/g' \
    -e 's|\$\{CLAUDE_PLUGIN_ROOT\}|.roo/skills|g' \
    "$SRC"
} > "$OUT"

echo "adapted: $SRC -> $OUT"
