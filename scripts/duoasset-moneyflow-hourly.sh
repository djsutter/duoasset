#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP="/usr/bin/php"

cd "$APP_DIR"

echo "=================================================================="
echo "Money flow hourly scan started: $(date)"
echo "=================================================================="


"$PHP" artisan moneyflow:update \
--interval="hourly" \
-vv

echo
echo "=================================================================="
echo "Money flow hourly scan finished: $(date)"
echo "=================================================================="
