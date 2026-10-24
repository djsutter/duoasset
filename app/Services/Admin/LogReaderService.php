<?php

namespace App\Services\Admin;

/**
 * Service for reading, parsing, categorizing, and filtering application logs
 * for the administrator observability console.
 */
class LogReaderService
{
    public const TYPE_ALL = 'all';
    public const TYPE_NOTIFICATIONS = 'notifications';
    public const TYPE_SETUP = 'setup';
    public const TYPE_EPS = 'eps';
    public const TYPE_SYSTEM = 'system';

    public const LEVEL_ALL = 'all';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO = 'info';

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_ALL => 'All Types',
            self::TYPE_NOTIFICATIONS => 'Notifications / Email',
            self::TYPE_SETUP => 'Setup Processing',
            self::TYPE_EPS => 'EPS Processing',
            self::TYPE_SYSTEM => 'System / General',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function levelLabels(): array
    {
        return [
            self::LEVEL_ALL => 'All Levels',
            self::LEVEL_ERROR => 'Errors / Critical',
            self::LEVEL_WARNING => 'Warnings',
            self::LEVEL_INFO => 'Info',
        ];
    }

    /**
     * Categorize a log line into a domain type.
     *
     * @param  array<string, mixed>  $context
     */
    public function categorize(string $message, string $channel = '', array $context = []): string
    {
        $text = strtolower($message.' '.json_encode($context));

        // Notifications / Email
        if (
            str_contains($text, 'notify')
            || str_contains($text, 'notifying')
            || str_contains($text, 'user_notified')
            || str_contains($text, 'extra_email')
            || str_contains($text, 'mail')
            || str_contains($text, 'email')
            || str_contains($text, 'smtp')
            || str_contains($text, 'notification')
            || str_contains($text, 'watchlist_user_notified')
            || str_contains($text, 'alerts.notifying')
        ) {
            return self::TYPE_NOTIFICATIONS;
        }

        // Setup Processing
        if (
            str_contains($text, 'buy_setup')
            || str_contains($text, 'scan-buy-setups')
            || str_contains($text, 'evaluatestockbuysetup')
            || str_contains($text, 'stockbuysetup')
        ) {
            return self::TYPE_SETUP;
        }

        // EPS / Earnings Processing
        if (
            str_contains($text, 'earnings')
            || str_contains($text, 'eps_revision')
            || str_contains($text, 'revision')
            || str_contains($text, 'scan-surprises')
            || str_contains($text, 'scan-revisions')
            || str_contains($text, 'enrichearningsevent')
            || str_contains($text, 'checkepsrevisionforsymbol')
        ) {
            return self::TYPE_EPS;
        }

        return self::TYPE_SYSTEM;
    }

    /**
     * Read and parse logs from file or content string.
     *
     * @return array{
     *     entries: array<int, array<string, mixed>>,
     *     summary: array<string, int>,
     *     file_exists: bool,
     *     file_size: int,
     *     file_path: string
     * }
     */
    public function getLogs(
        ?string $logFilePath = null,
        string $type = self::TYPE_ALL,
        string $level = self::LEVEL_ALL,
        string $search = '',
        int $limit = 100,
        int $maxBytes = 2097152 // 2MB
    ): array {
        $path = $logFilePath ?? storage_path('logs/laravel.log');
        $fileExists = file_exists($path);
        $fileSize = $fileExists ? (int) filesize($path) : 0;

        if (! $fileExists || $fileSize === 0) {
            return [
                'entries' => [],
                'summary' => [
                    'total_scanned' => 0,
                    'notifications' => 0,
                    'setup' => 0,
                    'eps' => 0,
                    'system' => 0,
                    'errors' => 0,
                    'warnings' => 0,
                    'info' => 0,
                ],
                'file_exists' => $fileExists,
                'file_size' => $fileSize,
                'file_path' => $path,
            ];
        }

        $rawContent = $this->readTail($path, $maxBytes);
        $parsedEntries = $this->parseLogContent($rawContent);

        $summary = [
            'total_scanned' => count($parsedEntries),
            'notifications' => 0,
            'setup' => 0,
            'eps' => 0,
            'system' => 0,
            'errors' => 0,
            'warnings' => 0,
            'info' => 0,
        ];

        foreach ($parsedEntries as $entry) {
            $eType = $entry['type'] ?? self::TYPE_SYSTEM;
            if (isset($summary[$eType])) {
                $summary[$eType]++;
            }

            $eLevel = strtolower($entry['level'] ?? 'info');
            if (in_array($eLevel, ['error', 'critical', 'alert', 'emergency'], true)) {
                $summary['errors']++;
            } elseif ($eLevel === 'warning') {
                $summary['warnings']++;
            } else {
                $summary['info']++;
            }
        }

        // Apply filters
        $filtered = array_filter($parsedEntries, function ($entry) use ($type, $level, $search) {
            // Type filter
            if ($type !== self::TYPE_ALL && $entry['type'] !== $type) {
                return false;
            }

            // Level filter
            if ($level !== self::LEVEL_ALL) {
                $eLevel = strtolower($entry['level'] ?? 'info');
                if ($level === self::LEVEL_ERROR && ! in_array($eLevel, ['error', 'critical', 'alert', 'emergency'], true)) {
                    return false;
                }
                if ($level === self::LEVEL_WARNING && $eLevel !== 'warning') {
                    return false;
                }
                if ($level === self::LEVEL_INFO && ! in_array($eLevel, ['info', 'notice', 'debug'], true)) {
                    return false;
                }
            }

            // Search filter
            if ($search !== '') {
                $needle = strtolower(trim($search));
                $haystack = strtolower(
                    $entry['message'].' '
                    .($entry['context_raw'] ?? '').' '
                    .($entry['symbol'] ?? '').' '
                    .($entry['stack_trace'] ?? '')
                );
                if (! str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        });

        return [
            'entries' => array_values(array_slice($filtered, 0, max(1, $limit))),
            'summary' => $summary,
            'file_exists' => true,
            'file_size' => $fileSize,
            'file_path' => $path,
        ];
    }

    /**
     * Read the last N bytes of a file safely.
     */
    protected function readTail(string $path, int $maxBytes): string
    {
        $size = (int) filesize($path);
        if ($size <= $maxBytes) {
            return (string) file_get_contents($path);
        }

        $fp = fopen($path, 'rb');
        if (! $fp) {
            return '';
        }

        fseek($fp, $size - $maxBytes);
        // Skip first partial line up to the newline
        fgets($fp);
        $content = stream_get_contents($fp);
        fclose($fp);

        return (string) $content;
    }

    /**
     * Parse raw Monolog text into structured entries (newest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseLogContent(string $content): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $content);
        if (! is_array($lines)) {
            return [];
        }

        $entries = [];
        $current = null;

        // Monolog standard pattern: [2026-10-24 06:23:42] env.LEVEL: message context extra
        $pattern = '/^\[(?<timestamp>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (?<channel>[a-zA-Z0-9_\-]+)\.(?<level>[A-Z]+): (?<rest>.*)$/';

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $matches)) {
                if ($current !== null) {
                    $entries[] = $this->finalizeEntry($current);
                }

                $rest = trim($matches['rest'] ?? '');
                $parsedRest = $this->splitMessageAndContext($rest);

                $current = [
                    'timestamp' => $matches['timestamp'],
                    'channel' => $matches['channel'],
                    'level' => strtoupper($matches['level']),
                    'message' => $parsedRest['message'],
                    'context' => $parsedRest['context'],
                    'context_raw' => $parsedRest['context_raw'],
                    'stack_lines' => [],
                    'raw' => $line,
                ];
            } else {
                if ($current !== null && trim($line) !== '') {
                    $current['stack_lines'][] = $line;
                    $current['raw'] .= "\n".$line;
                }
            }
        }

        if ($current !== null) {
            $entries[] = $this->finalizeEntry($current);
        }

        // Return newest first
        return array_reverse($entries);
    }

    /**
     * Split log message and json context.
     *
     * @return array{message: string, context: array<string, mixed>, context_raw: string}
     */
    protected function splitMessageAndContext(string $rest): array
    {
        $firstBrace = strpos($rest, '{');
        if ($firstBrace === false) {
            return [
                'message' => $rest,
                'context' => [],
                'context_raw' => '',
            ];
        }

        $message = trim(substr($rest, 0, $firstBrace));
        $contextRaw = trim(substr($rest, $firstBrace));

        $context = [];
        $decoded = json_decode($contextRaw, true);
        if (is_array($decoded)) {
            $context = $decoded;
        }

        return [
            'message' => $message !== '' ? $message : $rest,
            'context' => $context,
            'context_raw' => $contextRaw,
        ];
    }

    /**
     * Finalize an entry record with category, labels, and extracted symbol.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function finalizeEntry(array $entry): array
    {
        $category = $this->categorize($entry['message'] ?? '', $entry['channel'] ?? '', $entry['context'] ?? []);
        $entry['type'] = $category;
        $entry['type_label'] = static::typeLabels()[$category] ?? ucfirst($category);

        $stack = ! empty($entry['stack_lines']) ? implode("\n", $entry['stack_lines']) : '';
        $entry['stack_trace'] = $stack;
        unset($entry['stack_lines']);

        // Extract symbol if available in context
        $symbol = null;
        if (! empty($entry['context']['symbol'])) {
            $symbol = strtoupper(trim((string) $entry['context']['symbol']));
        } elseif (preg_match('/(?:\b|\"|\')([A-Z]{1,5})(?:\b|\"|\')/', $entry['message'], $m)) {
            // Only if message looks like a symbol mention
            if (in_array(strtoupper($m[1]), ['AAPL', 'MSFT', 'NVDA', 'TSLA', 'FEO', 'IGNT', 'PEAK'], true)) {
                $symbol = $m[1];
            }
        }
        $entry['symbol'] = $symbol;

        return $entry;
    }
}
