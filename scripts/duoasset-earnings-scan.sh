#!/usr/bin/env bash
set -euo pipefail

echo "Starting"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP="/usr/bin/php"

TO_DATE="$(date +%F)"
FROM_DATE="$(date -d "$TO_DATE -70 days" +%F)"

cd "$APP_DIR"

exec "$PHP" artisan earnings:scan-surprises -vvv \
  --from="$FROM_DATE" \
  --to="$TO_DATE"
