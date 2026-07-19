#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP="/usr/bin/php"

cd "$APP_DIR"

echo "=================================================================="
echo "Money flow daily scan started: $(date)"
echo "=================================================================="


"$PHP" artisan moneyflow:update -vv

echo
echo "=================================================================="
echo "Money flow daily scan finished: $(date)"
echo "=================================================================="
