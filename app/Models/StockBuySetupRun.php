<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Persisted run report for Stock Buy Setup scans.
 *
 * Captures candidates considered, scoring outcomes, rejects,
 * categorized rejection reasons, and top matches for administrative observability.
 */
class StockBuySetupRun extends Model
{
    use HasFactory;

    public const CATEGORY_INSUFFICIENT_HISTORY = 'insufficient_history';

    public const CATEGORY_BELOW_MIN_MARKET_CAP = 'below_min_market_cap';

    public const CATEGORY_ABOVE_MAX_MARKET_CAP = 'above_max_market_cap';

    public const CATEGORY_EXCHANGE_MISMATCH = 'exchange_mismatch';

    public const CATEGORY_TECHNICAL_CRITERIA_UNMET = 'technical_criteria_unmet';

    public const CATEGORY_EXECUTION_ERROR = 'execution_error';

    public const CATEGORY_OTHER = 'other';

    protected $table = 'stock_buy_setup_runs';

    protected $fillable = [
        'started_at',
        'completed_at',
        'status',
        'trigger',
        'symbols_considered',
        'scored_count',
        'matched_count',
        'rejected_count',
        'error_count',
        'categorized_reasons',
        'raw_reasons',
        'top_matches',
        'summary_data',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'symbols_considered' => 'integer',
        'scored_count' => 'integer',
        'matched_count' => 'integer',
        'rejected_count' => 'integer',
        'error_count' => 'integer',
        'categorized_reasons' => 'array',
        'raw_reasons' => 'array',
        'top_matches' => 'array',
        'summary_data' => 'array',
    ];

    /**
     * Categorize a rejection or error reason string into a high-level bucket.
     */
    public static function categorizeReason(string $reason): string
    {
        $lower = strtolower(trim($reason));

        if (str_contains($lower, 'insufficient history') || str_contains($lower, 'too new') || str_contains($lower, 'bars)')) {
            return self::CATEGORY_INSUFFICIENT_HISTORY;
        }

        if (str_contains($lower, 'below setup minimum') || str_contains($lower, 'market cap below') || str_contains($lower, 'min_market_cap')) {
            return self::CATEGORY_BELOW_MIN_MARKET_CAP;
        }

        if (str_contains($lower, 'above setup maximum') || str_contains($lower, 'market cap above') || str_contains($lower, 'max_market_cap')) {
            return self::CATEGORY_ABOVE_MAX_MARKET_CAP;
        }

        if (str_contains($lower, 'exchange')) {
            return self::CATEGORY_EXCHANGE_MISMATCH;
        }

        if (
            str_contains($lower, 'no enabled setup')
            || str_contains($lower, 'no detector match')
            || str_contains($lower, 'detector')
            || str_contains($lower, 'spike')
            || str_contains($lower, 'consolidation')
            || str_contains($lower, 'atr')
            || str_contains($lower, 'base')
            || str_contains($lower, 'heartbeat')
        ) {
            return self::CATEGORY_TECHNICAL_CRITERIA_UNMET;
        }

        if (str_contains($lower, 'error') || str_contains($lower, 'failed') || str_contains($lower, 'exception')) {
            return self::CATEGORY_EXECUTION_ERROR;
        }

        return self::CATEGORY_OTHER;
    }

    /**
     * Map category key to a human-readable title.
     *
     * @return array<string, string>
     */
    public static function categoryLabels(): array
    {
        return [
            self::CATEGORY_INSUFFICIENT_HISTORY => 'Too New / Insufficient History (< 252 bars)',
            self::CATEGORY_BELOW_MIN_MARKET_CAP => 'Below Market Cap Minimum',
            self::CATEGORY_ABOVE_MAX_MARKET_CAP => 'Above Market Cap Maximum',
            self::CATEGORY_EXCHANGE_MISMATCH => 'Exchange Mismatch',
            self::CATEGORY_TECHNICAL_CRITERIA_UNMET => 'Technical Setup Criteria Unmet',
            self::CATEGORY_EXECUTION_ERROR => 'Processing / API Errors',
            self::CATEGORY_OTHER => 'Other Rejections',
        ];
    }

    public static function categoryLabel(string $category): string
    {
        return static::categoryLabels()[$category] ?? ucfirst(str_replace('_', ' ', $category));
    }

    /**
     * Record an individual symbol's debug evaluation into an ongoing run.
     *
     * @param  array<string, mixed>  $result
     */
    public static function recordResult(int $runId, array $result): void
    {
        DB::transaction(function () use ($runId, $result) {
            /** @var StockBuySetupRun|null $run */
            $run = static::lockForUpdate()->find($runId);
            if (! $run) {
                return;
            }

            $run->scored_count++;
            $status = (string) ($result['status'] ?? 'unknown');

            if ($status === 'matched') {
                $run->matched_count++;
            } elseif ($status === 'error') {
                $run->error_count++;
            } else {
                $run->rejected_count++;
            }

            $reason = (string) ($result['reason'] ?? '');
            if ($reason !== '') {
                $raw = $run->raw_reasons ?? [];
                $raw[$reason] = ($raw[$reason] ?? 0) + 1;
                $run->raw_reasons = $raw;

                $category = static::categorizeReason($reason);
                $cat = $run->categorized_reasons ?? [];
                $cat[$category] = ($cat[$category] ?? 0) + 1;
                $run->categorized_reasons = $cat;
            }

            foreach ((array) ($result['matches'] ?? []) as $match) {
                if (isset($match['setup_score'])) {
                    $top = $run->top_matches ?? [];
                    $top[] = [
                        'symbol' => (string) ($result['symbol'] ?? ''),
                        'setup_type' => (string) ($match['setup_type'] ?? ''),
                        'score' => (int) ($match['setup_score'] ?? 0),
                        'status' => (string) ($match['status'] ?? ''),
                    ];
                    usort($top, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
                    $run->top_matches = array_slice($top, 0, 20);
                }
            }

            if ($run->symbols_considered > 0 && $run->scored_count >= $run->symbols_considered) {
                $run->status = 'completed';
                $run->completed_at = now();
            }

            $run->save();
        });
    }

    /**
     * Finalize run with summary statistics from synchronous execution.
     *
     * @param  array<string, mixed>  $summary
     */
    public function finishSync(array $summary, float $elapsedSeconds): void
    {
        $categorized = [];
        foreach (($summary['reasons'] ?? []) as $reason => $count) {
            $cat = static::categorizeReason((string) $reason);
            $categorized[$cat] = ($categorized[$cat] ?? 0) + $count;
        }

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'scored_count' => (int) ($summary['processed'] ?? 0),
            'matched_count' => (int) ($summary['matched'] ?? 0),
            'rejected_count' => (int) ($summary['rejected'] ?? 0),
            'error_count' => (int) ($summary['errors'] ?? 0),
            'categorized_reasons' => $categorized,
            'raw_reasons' => $summary['reasons'] ?? [],
            'top_matches' => array_slice($summary['top'] ?? [], 0, 20),
            'summary_data' => array_merge($this->summary_data ?? [], [
                'elapsed_seconds' => round($elapsedSeconds, 2),
                'created' => (int) ($summary['created'] ?? 0),
                'existing' => (int) ($summary['existing'] ?? 0),
            ]),
        ]);
    }

    /**
     * Compute elapsed execution time in seconds.
     */
    public function elapsedSeconds(): ?float
    {
        if (isset($this->summary_data['elapsed_seconds'])) {
            return (float) $this->summary_data['elapsed_seconds'];
        }

        if ($this->started_at && $this->completed_at) {
            return (float) $this->started_at->diffInSeconds($this->completed_at);
        }

        if ($this->started_at) {
            return (float) $this->started_at->diffInSeconds(now());
        }

        return null;
    }

    /**
     * Format duration string (e.g. "12.4s" or "3m 42s").
     */
    public function formattedDuration(): string
    {
        $seconds = $this->elapsedSeconds();
        if ($seconds === null) {
            return '—';
        }

        if ($seconds < 60) {
            return number_format($seconds, 1).'s';
        }

        $minutes = floor($seconds / 60);
        $remaining = $seconds - ($minutes * 60);

        return sprintf('%dm %02ds', $minutes, (int) round($remaining));
    }

    /**
     * Structured breakdown of rejections by category with percentages.
     *
     * @return array<int, array{key: string, label: string, count: int, pct_of_rejected: float, pct_of_scored: float}>
     */
    public function rejectionBreakdown(): array
    {
        $categorized = $this->categorized_reasons ?? [];
        $totalRejected = max(1, $this->rejected_count);
        $totalScored = max(1, $this->scored_count);
        $breakdown = [];

        foreach (static::categoryLabels() as $key => $label) {
            $count = (int) ($categorized[$key] ?? 0);
            $breakdown[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'pct_of_rejected' => round(($count / $totalRejected) * 100, 1),
                'pct_of_scored' => round(($count / $totalScored) * 100, 1),
            ];
        }

        // Sort by count descending
        usort($breakdown, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $breakdown;
    }
}
