#!/usr/bin/env bash
set -euo pipefail

echo "Starting"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP="/usr/bin/php"

TO_DATE="$(date +%F)"
FROM_DATE="$(date -d "$TO_DATE -70 days" +%F)"

cd "$APP_DIR"

"$PHP" artisan migrate --force

"$PHP" artisan digest:start-collection eps_surprise --from="$FROM_DATE" --to="$TO_DATE"

if ! "$PHP" artisan earnings:scan-surprises -vvv \
  --fresh \
  --from="$FROM_DATE" \
  --to="$TO_DATE"; then
  echo "WARNING: earnings:scan-surprises exited with non-zero status; preparing digest from collected results"
fi

exec "$PHP" artisan digest:prepare eps_surprise
