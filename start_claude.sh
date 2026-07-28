#!/bin/bash

set -eu

# Credentials must be supplied by the caller's environment or secret manager.
: "${ANTHROPIC_API_KEY:?Set ANTHROPIC_API_KEY before running this script}"

export ANTHROPIC_BASE_URL="${ANTHROPIC_BASE_URL:-https://agentrouter.org/}"
export ANTHROPIC_MODEL="${ANTHROPIC_MODEL:-claude-opus-4-6}"

exec claude
