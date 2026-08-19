#!/usr/bin/env sh
# Autnyx V2 — pre-push verification
# ---------------------------------------------------------------
# Run this before every push:   sh scripts/verify.sh
#
# Tier 1 (always, needs only PHP):
#   - php -l syntax lint of all PHP
#   - scripts/check-blade.php  (the INC-001..003 footgun scan)
#
# Tier 2 (only when vendor/ is installed — i.e. CI or a full local setup):
#   - the composer compile-gate (view:cache + filament:cache-components)
#   - php artisan test  (unit/feature + route smoke tests)
#
# On a machine without vendor/ (the normal Autnyx dev box), Tier 1 runs and
# Tier 2 is skipped with a note — Tier 2 then runs for real on Laravel Cloud's
# build and in GitHub Actions CI.
#
# Exit non-zero on the first failure so it can gate a push.
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> Tier 1a: PHP syntax lint"
# Lint every tracked .php file under the app source dirs.
FAIL=0
for dir in app routes database config tests; do
  [ -d "$dir" ] || continue
  find "$dir" -name '*.php' -print0 | while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null 2>&1 || { echo "  SYNTAX ERROR: $f"; php -l "$f"; exit 1; }
  done || FAIL=1
done
[ "$FAIL" = "0" ] || { echo "PHP syntax lint failed."; exit 1; }
echo "    ok"

echo "==> Tier 1b: Blade / Filament footgun scan"
php scripts/check-blade.php

if [ -f vendor/autoload.php ]; then
  echo "==> Tier 2a: compile gate (view:cache + filament:cache-components)"
  composer run compile-gate

  echo "==> Tier 2b: test suite (unit + feature + smoke)"
  php artisan test
else
  echo "==> Tier 2 skipped: vendor/ not installed here."
  echo "    The full compile gate + tests run on Laravel Cloud build and in CI."
fi

echo ""
echo "✓ verify passed."
