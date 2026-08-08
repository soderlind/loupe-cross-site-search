#!/usr/bin/env bash
#
# Build the distributable plugin zip (mirrors the CI build-zip workflow).
# Stages a clean copy, installs runtime deps with --no-dev, applies .distignore,
# and zips — without touching the working tree's vendor/.
#
set -euo pipefail

SLUG="loupe-cross-site-search"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/$SLUG.zip"
STAGE="$(mktemp -d)"
RAW="$STAGE/raw"
FINAL="$STAGE/$SLUG"

cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

echo "→ Staging source…"
mkdir -p "$RAW"
rsync -a --exclude='.git/' --exclude='node_modules/' --exclude='vendor/' "$ROOT/" "$RAW/"

echo "→ Installing runtime dependencies (--no-dev)…"
( cd "$RAW" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )

echo "→ Applying .distignore…"
mkdir -p "$FINAL"
rsync -a --exclude='.git/' --exclude='node_modules/' --exclude-from="$ROOT/.distignore" "$RAW/" "$FINAL/"

echo "→ Zipping…"
rm -f "$OUT"
( cd "$STAGE" && zip -rq "$OUT" "$SLUG" )

echo "✓ Built $OUT ($(cd "$FINAL" && find . -type f | wc -l | tr -d ' ') files)"
