#!/usr/bin/env bash
# sync-superpowers.sh - Pull obra/superpowers at a pinned commit and adapt all
# 14 skills into .roo/skills/superpowers/.
#
# Idempotent: safe to re-run. Re-running with a new UPSTREAM_SHA refreshes the
# vendored content (review the diff before committing).
#
# Usage: scripts/sync-superpowers.sh
# Requires: git, bash 4+, sed.

set -euo pipefail

UPSTREAM_REPO="https://github.com/obra/superpowers.git"
UPSTREAM_SHA="f2cbfbefebbfef77321e4c9abc9e949826bea9d7"

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "==> cloning $UPSTREAM_REPO @ $UPSTREAM_SHA"
git clone --quiet "$UPSTREAM_REPO" "$WORK/src"
git -C "$WORK/src" checkout --quiet "$UPSTREAM_SHA"

VENDOR_DIR="$REPO_ROOT/vendor/superpowers"
SKILLS_OUT="$REPO_ROOT/.roo/skills/superpowers"
mkdir -p "$VENDOR_DIR" "$SKILLS_OUT"

# Provenance: keep LICENSE, README, and the pinned SHA. We do NOT vendor the
# full upstream tree (10k+ files); that would bloat the kwatog repo without
# adding value. Adapted skill content lives in .roo/skills/superpowers/.
cp "$WORK/src/LICENSE" "$VENDOR_DIR/LICENSE"
cp "$WORK/src/README.md" "$VENDOR_DIR/README.upstream.md"
echo "$UPSTREAM_SHA" > "$VENDOR_DIR/UPSTREAM_SHA"

echo "==> adapting skills"
PORTED=()
for SKILL_PATH in "$WORK/src/skills"/*/SKILL.md; do
  SKILL_NAME="$(basename "$(dirname "$SKILL_PATH")")"
  OUT="$SKILLS_OUT/${SKILL_NAME}.md"
  "$REPO_ROOT/scripts/adapt-skill.sh" "$SKILL_PATH" "superpowers" "$UPSTREAM_SHA" "$OUT"
  PORTED+=("$SKILL_NAME")
done

echo "==> ${#PORTED[@]} skills adapted into $SKILLS_OUT"
echo "==> done. Review changes with: git status && git diff"
