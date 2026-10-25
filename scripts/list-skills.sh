#!/usr/bin/env bash
# list-skills.sh - Print every adapted skill in .roo/skills/ along with its
# upstream description (parsed from front-matter or first heading).
#
# Used by humans and by agents during a task to discover available skills
# before consulting one.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SKILLS_ROOT="$REPO_ROOT/.roo/skills"

if [[ ! -d "$SKILLS_ROOT" ]]; then
  echo "no skills found at $SKILLS_ROOT (run scripts/sync-superpowers.sh and scripts/sync-ruflo.sh first)" >&2
  exit 1
fi

for VENDOR_DIR in "$SKILLS_ROOT"/*/; do
  VENDOR="$(basename "$VENDOR_DIR")"
  echo "## ${VENDOR}"
  for SKILL in "$VENDOR_DIR"*.md; do
    [[ -f "$SKILL" ]] || continue
    [[ "$(basename "$SKILL")" == "INDEX.md" ]] && continue
    NAME="$(basename "$SKILL" .md)"
    # Pull the description from front-matter (handles single-line and
    # multi-line | block scalar forms) with awk.
    DESC=$(awk '
      /^---[[:space:]]*$/ { fm = !fm; next }
      fm && /^description:/ {
        sub(/^description:[[:space:]]*/, "")
        sub(/^[|>][-+]?[[:space:]]*/, "")
        gsub(/"/, "")
        if (length($0) > 0) { print; exit }
        getline next_line
        sub(/^[[:space:]]+/, "", next_line)
        print next_line
        exit
      }
    ' "$SKILL")
    [[ -z "$DESC" ]] && DESC="(no description)"
    printf "  - .roo/skills/%s/%s.md - %s\n" "$VENDOR" "$NAME" "$DESC"
  done
  echo
done
