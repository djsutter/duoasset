<?php

use App\Models\CompanyFinancial;
use App\Models\CompanyProfile;
use App\Models\MarketDataPayload;
use App\Models\StockBuySetupAlert;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScorer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            StockBuySetupAlert::ensureBookValueSchema();

            // 1. Backfill missing reported_currency and fiscal_year on company_financials from raw_metrics
            if (Schema::hasTable('company_financials')) {
                CompanyFinancial::query()
                    ->where(function ($q) {
                        $q->whereNull('reported_currency')
                            ->orWhereNull('fiscal_year');
                    })
                    ->chunkById(200, function ($rows) {
                        foreach ($rows as $row) {
                            $raw = is_array($row->raw_metrics) ? $row->raw_metrics : [];
                            $dirty = false;

                            if ($row->reported_currency === null && ! empty($raw['reportedCurrency']) && is_string($raw['reportedCurrency'])) {
                                $row->reported_currency = strtoupper(trim($raw['reportedCurrency']));
                                $dirty = true;
                            }

                            if ($row->fiscal_year === null && isset($raw['fiscalYear']) && is_numeric($raw['fiscalYear'])) {
                                $row->fiscal_year = (int) $raw['fiscalYear'];
                                $dirty = true;
                            }

                            if ($dirty) {
                                $row->saveQuietly();
                            }
                        }
                    });
            }

            // 2. Backfill missing shares_outstanding (from market_cap / price) and currency on company_profiles
            if (Schema::hasTable('company_profiles')) {
                $hasPayloads = Schema::hasTable('market_data_payloads');

                CompanyProfile::query()
                    ->where(function ($q) {
                        $q->whereNull('shares_outstanding')
                            ->orWhere('shares_outstanding', '<=', 0)
                            ->orWhereNull('currency')
                            ->orWhere('currency', '');
                    })
                    ->chunkById(200, function ($profiles) use ($hasPayloads) {
                        foreach ($profiles as $profile) {
                            $dirty = false;
                            $sym = strtoupper(trim((string) $profile->symbol));

                            if ($profile->shares_outstanding === null || (int) $profile->shares_outstanding <= 0) {
                                $cap = $profile->market_cap !== null ? (float) $profile->market_cap : 0.0;
                                $price = $profile->price !== null ? (float) $profile->price : 0.0;
                                if ($cap > 0.0 && $price > 0.0) {
                                    $derived = (int) round($cap / $price);
                                    if ($derived > 0) {
                                        $profile->shares_outstanding = $derived;
                                        $dirty = true;
                                    }
                                }
                            }

                            if ($profile->currency === null || trim((string) $profile->currency) === '') {
                                $currency = null;
                                if ($hasPayloads && $sym !== '') {
                                    $payloadRow = MarketDataPayload::query()
                                        ->where('symbol', $sym)
                                        ->where('data_type', 'profile')
                                        ->orderByDesc('retrieved_at')
                                        ->first();
                                    if ($payloadRow && is_array($payloadRow->payload) && ! empty($payloadRow->payload['currency'])) {
                                        $currency = strtoupper(trim((string) $payloadRow->payload['currency']));
                                    }
                                }
                                if ($currency === null) {
                                    $ex = strtoupper(trim((string) ($profile->exchange ?? '')));
                                    if (
                                        in_array($ex, ['TSX', 'TSXV', 'TSE', 'CVE', 'NEO', 'CSE'], true)
                                        || str_ends_with($sym, '.TO')
                                        || str_ends_with($sym, '.V')
                                        || str_ends_with($sym, '.CN')
                                        || str_ends_with($sym, '.NE')
                                    ) {
                                        $currency = 'CAD';
                                    } elseif (in_array($ex, ['NYSE', 'NASDAQ', 'AMEX', 'NMS', 'NYQ', 'NGM', 'NCM', 'BATS', 'ARCA'], true)) {
                                        $currency = 'USD';
                                    }
                                }
                                if ($currency !== null) {
                                    $profile->currency = $currency;
                                    $dirty = true;
                                }
                            }

                            if ($dirty) {
                                $profile->saveQuietly();
                            }
                        }
                    });
            }

            // 3. Ensure persisted buy_setup_config includes Book Value keys; leave alert score
            // recalculation to the next scanner run so score_delta reflects component/weight adjustments.
            app(BuySetupConfigService::class)->ensureBookValueConfigMigrated();
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        // Corrective data migration; no schema rollback needed.
    }
};
