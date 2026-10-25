#!/usr/bin/env bash
# sync-ruflo.sh - Pull ruvnet/ruflo at a pinned commit and adapt a CURATED
# subset of skills into .roo/skills/ruflo/.
#
# Why a subset? Ruflo ships ~369 SKILL.md files but the vast majority require
# ruflo's runtime: an MCP server, daemon, swarm orchestrator, agentdb, hooks,
# and Cognitum.One backends. Those cannot be embedded in this ephemeral
# sandbox. We port only skills that work as standalone process guidance.
#
# Anything not in CURATED is recorded in INDEX.md as "Not ported" with reason.
#
# Usage: scripts/sync-ruflo.sh
# Requires: git, bash 4+, sed.

set -euo pipefail

UPSTREAM_REPO="https://github.com/ruvnet/ruflo.git"
UPSTREAM_SHA="f1a82d9ee327eaeb92f0f8d59ad17b1048695f55"

# Curated list. Add carefully: each addition must be portable as standalone
# guidance (no runtime calls like `npx claude-flow@alpha ...`).
CURATED=(
  "sparc-methodology"
  "pair-programming"
  "skill-builder"
)

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "==> cloning $UPSTREAM_REPO @ $UPSTREAM_SHA"
git clone --quiet "$UPSTREAM_REPO" "$WORK/src"
git -C "$WORK/src" checkout --quiet "$UPSTREAM_SHA"

VENDOR_DIR="$REPO_ROOT/vendor/ruflo"
SKILLS_OUT="$REPO_ROOT/.roo/skills/ruflo"
mkdir -p "$VENDOR_DIR" "$SKILLS_OUT"

cp "$WORK/src/LICENSE" "$VENDOR_DIR/LICENSE"
# Trim README to first 200 lines (it's 24k+ chars with embedded GIFs and badges)
head -200 "$WORK/src/README.md" > "$VENDOR_DIR/README.upstream.md"
echo "$UPSTREAM_SHA" > "$VENDOR_DIR/UPSTREAM_SHA"

echo "==> adapting curated skills (${#CURATED[@]})"
PORTED=()
for NAME in "${CURATED[@]}"; do
  SRC="$WORK/src/.claude/skills/$NAME/SKILL.md"
  if [[ ! -f "$SRC" ]]; then
    echo "WARN: curated skill not found upstream: $NAME ($SRC)" >&2
    continue
  fi
  OUT="$SKILLS_OUT/${NAME}.md"
  "$REPO_ROOT/scripts/adapt-skill.sh" "$SRC" "ruflo" "$UPSTREAM_SHA" "$OUT"
  PORTED+=("$NAME")
done

# Build a simple "not ported" inventory for INDEX.md.
NOT_PORTED_LIST="$VENDOR_DIR/.not-ported.txt"
{
  for SKILL_PATH in "$WORK/src/.claude/skills"/*/SKILL.md; do
    NAME="$(basename "$(dirname "$SKILL_PATH")")"
    skip=0
    for KEEP in "${CURATED[@]}"; do
      [[ "$NAME" == "$KEEP" ]] && skip=1 && break
    done
    [[ $skip -eq 0 ]] && echo "$NAME"
  done
} > "$NOT_PORTED_LIST"

echo "==> ${#PORTED[@]} ruflo skills adapted; $(wc -l < "$NOT_PORTED_LIST") skills marked not ported"
echo "==> done. Review changes with: git status && git diff"
