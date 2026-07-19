#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

PHP="/usr/bin/php"

EXCHANGES=("NYSE" "NASDAQ" "TSX" "TSXV")
LETTERS=({A..Z})

cd "$APP_DIR"

echo "=================================================================="
echo "Buy setup scan started: $(date)"
echo "=================================================================="

total=$(( ${#EXCHANGES[@]} * ${#LETTERS[@]} ))
count=0

for exchange in "${EXCHANGES[@]}"; do
    for letter in "${LETTERS[@]}"; do
        ((++count))

        echo
        echo "------------------------------------------------------------------"
        echo "[$count/$total] $exchange $letter ($(date))"
        echo "------------------------------------------------------------------"

        "$PHP" artisan stocks:scan-buy-setups \
            --exchange="$exchange" \
            --letter="$letter" \
            --sync \
            -vv

        echo "Completed $exchange $letter ($(date))"
    done
done

echo
echo "=================================================================="
echo "Buy setup scan finished: $(date)"
echo "=================================================================="
