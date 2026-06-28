<?php

namespace App\Models\Concerns;

/**
 * Adds a computed `market_cap` accessor to a model.
 *
 * Market cap is defined as: current share price × shares_outstanding.
 * When either input is missing, the trait falls back to the raw value
 * stored in the database column (kept for backward compatibility and
 * for DB-level filters like `where('market_cap', '>=', X)`).
 *
 * Models that use this trait MUST expose:
 *   - a numeric "price" attribute (`getMarketCapPriceAttribute` may be
 *     overridden to point to a custom column, e.g. `last_price`); and
 *   - a `shares_outstanding` column (or override `getMarketCapShares`).
 *
 * The accessor is intentionally simple — it returns an integer (USD,
 * whole units) so existing callers like `(int) $event->market_cap`
 * keep working unchanged.
 */
trait HasComputedMarketCap
{
    /**
     * Computed market cap = price × shares_outstanding, with a fallback
     * to the raw stored `market_cap` value when either input is missing.
     */
    public function getMarketCapAttribute(): ?int
    {
        $stored = $this->attributes['market_cap'] ?? null;
        $price = $this->getMarketCapPrice();
        $shares = $this->getMarketCapShares();

        if ($price !== null && $shares !== null && $price > 0 && $shares > 0) {
            return (int) round($price * $shares);
        }

        if ($stored === null || $stored === '') {
            return null;
        }

        return is_numeric($stored) ? (int) $stored : null;
    }

    /**
     * Price used for the computation. Override on models whose price
     * column is named differently (e.g. Stock::last_price as FiatMoney).
     */
    protected function getMarketCapPrice(): ?float
    {
        $price = $this->attributes['price'] ?? null;

        if ($price === null || $price === '') {
            return null;
        }

        return is_numeric($price) ? (float) $price : null;
    }

    /**
     * Shares outstanding used for the computation.
     */
    protected function getMarketCapShares(): ?int
    {
        $shares = $this->attributes['shares_outstanding'] ?? null;

        if ($shares === null || $shares === '') {
            return null;
        }

        return is_numeric($shares) ? (int) $shares : null;
    }
}
