<?php

namespace App\Services\Stocks;

/**
 * Validates whether a financial security is active common equity,
 * excluding redeemed bonds, active notes, preferred shares, warrants,
 * rights, units, funds/ETFs, and inactive or delisted securities.
 */
class EquitySecurityValidator
{
    /**
     * Known non-equity debt/notes mapped to their actual common stock symbol.
     *
     * @var array<string, array{type: string, equity: string, reason: string}>
     */
    protected const KNOWN_DEBT_MAPPINGS = [
        'BWNB' => [
            'type' => EquityValidationResult::TYPE_REDEEMED_BOND,
            'equity' => 'BW',
            'reason' => 'Non-equity instrument: redeemed bond (BWNB is a redeemed bond; actual common equity is BW)',
        ],
        'BWSN' => [
            'type' => EquityValidationResult::TYPE_BOND,
            'equity' => 'BW',
            'reason' => 'Non-equity instrument: corporate senior notes (BWSN is a bond; actual common equity is BW)',
        ],
    ];

    /**
     * Validate whether a symbol and its metadata qualify as active common equity.
     *
     * @param  array<string, mixed>|null  $profile
     */
    public function validate(string $symbol, ?string $companyName = null, ?array $profile = null): EquityValidationResult
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return new EquityValidationResult(
                isValid: false,
                instrumentType: EquityValidationResult::TYPE_OTHER_NON_EQUITY,
                reason: 'Empty symbol',
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        // Check explicit known mappings (e.g. BWNB -> BW)
        if (isset(self::KNOWN_DEBT_MAPPINGS[$symbol])) {
            $known = self::KNOWN_DEBT_MAPPINGS[$symbol];

            return new EquityValidationResult(
                isValid: false,
                instrumentType: $known['type'],
                reason: $known['reason'],
                underlyingSymbol: $known['equity'],
                details: ['mapped' => true],
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        $profile = $profile ?? [];
        $name = trim((string) ($companyName ?: ($profile['company_name'] ?? $profile['name'] ?? '')));
        // NOTE: General business description ($profile['description']) is intentionally
        // NOT inspected for instrument classification to avoid false positives on legitimate
        // equities whose business descriptions reference bonds, notes, warrants, or financing.
        $type = strtolower(trim((string) ($profile['type'] ?? $profile['security_type'] ?? '')));

        $underlying = $this->resolveUnderlyingEquitySymbol($symbol, $name, $profile);

        // 1. Inactive or delisted security checks (structured metadata)
        $isActivelyTrading = $profile['is_actively_trading'] ?? $profile['isActivelyTrading'] ?? null;
        if ($isActivelyTrading !== null && filter_var($isActivelyTrading, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false) {
            $isDebt = $this->matchesDebtPatterns($symbol, $name, $type);
            $instrumentType = $isDebt ? EquityValidationResult::TYPE_REDEEMED_BOND : EquityValidationResult::TYPE_INACTIVE_SECURITY;
            $reason = $isDebt
                ? "Inactive security: redeemed bond ({$symbol}".($underlying ? "; actual common equity is {$underlying}" : '').')'
                : "Inactive security: not actively trading ({$symbol})";

            return new EquityValidationResult(
                isValid: false,
                instrumentType: $instrumentType,
                reason: $reason,
                underlyingSymbol: $underlying,
                details: ['is_actively_trading' => false],
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        // Check for textual inactive / redemption indicators in the security listing name ONLY
        if ($name !== '' && preg_match('/\b(redeemed|called|delisted|matured|liquidated|expired|inactive)\b/i', $name, $inactiveMatches)) {
            $matchedWord = strtolower($inactiveMatches[1]);
            $isDebt = $this->matchesDebtPatterns($symbol, $name, $type);
            $instrumentType = $isDebt ? EquityValidationResult::TYPE_REDEEMED_BOND : EquityValidationResult::TYPE_INACTIVE_SECURITY;
            $reason = $isDebt
                ? "Non-equity instrument: redeemed bond ({$symbol}".($underlying ? "; actual common equity is {$underlying}" : '').')'
                : "Inactive security: {$matchedWord} ({$symbol})";

            return new EquityValidationResult(
                isValid: false,
                instrumentType: $instrumentType,
                reason: $reason,
                underlyingSymbol: $underlying,
                details: ['inactive_keyword' => $matchedWord],
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        // 2. Bonds, Notes, Debentures, Baby Bonds
        if ($this->matchesDebtPatterns($symbol, $name, $type)) {
            return new EquityValidationResult(
                isValid: false,
                instrumentType: EquityValidationResult::TYPE_BOND,
                reason: "Non-equity instrument: bond/note ({$symbol}".($underlying ? "; actual common equity is {$underlying}" : '').')',
                underlyingSymbol: $underlying,
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        // 3. Preferred Stock
        if ($this->matchesPreferredPatterns($symbol, $name, $type)) {
            return new EquityValidationResult(
                isValid: false,
                instrumentType: EquityValidationResult::TYPE_PREFERRED_STOCK,
                reason: "Non-equity instrument: preferred stock ({$symbol}".($underlying ? "; actual common equity is {$underlying}" : '').')',
                underlyingSymbol: $underlying,
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        // 4. Warrants
        if ($this->matchesWarrantPatterns($symbol, $name, $type)) {
            return new EquityValidationResult(
                isValid: false,
                instrumentType: EquityValidationResult::TYPE_WARRANT,
                reason: "Non-equity instrument: warrant ({$symbol}".($underlying ? "; actual common equity is {$underlying}" : '').')',
                underlyingSymbol: $underlying,
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        // 5. Rights
        if ($this->matchesRightsPatterns($symbol, $name, $type)) {
            return new EquityValidationResult(
                isValid: false,
                instrumentType: EquityValidationResult::TYPE_RIGHT,
                reason: "Non-equity instrument: rights ({$symbol}".($underlying ? "; actual common equity is {$underlying}" : '').')',
                underlyingSymbol: $underlying,
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        // 6. Units
        if ($this->matchesUnitPatterns($symbol, $name, $type)) {
            return new EquityValidationResult(
                isValid: false,
                instrumentType: EquityValidationResult::TYPE_UNIT,
                reason: "Non-equity instrument: unit ({$symbol}".($underlying ? "; actual common equity is {$underlying}" : '').')',
                underlyingSymbol: $underlying,
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        // 7. Funds, ETFs, ETNs
        if ($this->matchesFundOrEtfPatterns($symbol, $name, $type, $profile)) {
            return new EquityValidationResult(
                isValid: false,
                instrumentType: EquityValidationResult::TYPE_FUND_OR_ETF,
                reason: "Non-equity instrument: ETF or fund ({$symbol})",
                underlyingSymbol: $underlying,
                status: EquityValidationResult::STATUS_CONFIRMED_NON_EQUITY,
            );
        }

        // 8. Distinguish Confirmed Common Equity from Insufficient Metadata
        // Note: 'confirmed_common_equity' represents classification based on available provider
        // metadata (structured type, ADR flags, or explicit common stock listing name), rather
        // than definitive legal verification of common-share status. ADRs remain fully eligible.
        // Being actively traded, having isEtf=false/isFund=false, or having a corporate name does NOT establish common equity.
        $hasStructuredEquityType = in_array($type, ['stock', 'common stock', 'cs', 'adr', 'equity'], true)
            || (isset($profile['is_adr']) && filter_var($profile['is_adr'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true)
            || (isset($profile['isAdr']) && filter_var($profile['isAdr'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true);

        $hasExplicitEquityName = $name !== '' && (bool) preg_match('/\b(Common Stock|Common Shares?|Ordinary Shares?|ADR|ADS|American Depositary Receipts?|American Depositary Shares?)\b/i', $name);

        if ($hasStructuredEquityType || $hasExplicitEquityName) {
            return new EquityValidationResult(
                isValid: true,
                instrumentType: EquityValidationResult::TYPE_COMMON_EQUITY,
                reason: null,
                underlyingSymbol: $symbol,
                status: EquityValidationResult::STATUS_CONFIRMED_EQUITY,
            );
        }

        // Conservative fallback policy:
        // Do not silently treat an unrecognized instrument as confirmed common equity,
        // but avoid accidental exclusion of legitimate common equities with incomplete metadata.
        return new EquityValidationResult(
            isValid: true,
            instrumentType: EquityValidationResult::TYPE_INSUFFICIENT_METADATA,
            reason: null,
            underlyingSymbol: $symbol,
            details: ['insufficient_metadata' => true, 'provisional' => true],
            status: EquityValidationResult::STATUS_INSUFFICIENT_METADATA,
        );
    }

    /**
     * Determine whether the security is active common equity.
     */
    public function isCommonEquity(string $symbol, ?string $companyName = null, ?array $profile = null): bool
    {
        return $this->validate($symbol, $companyName, $profile)->isCommonEquity();
    }

    /**
     * Determine whether the security is eligible for equity scanners.
     */
    public function isEligibleEquity(string $symbol, ?string $companyName = null, ?array $profile = null): bool
    {
        return $this->validate($symbol, $companyName, $profile)->isValid();
    }

    /**
     * Get the rejection reason for a security, or null if it is valid common equity.
     */
    public function rejectionReason(string $symbol, ?string $companyName = null, ?array $profile = null): ?string
    {
        $result = $this->validate($symbol, $companyName, $profile);

        return $result->isValid() ? null : $result->reason();
    }

    /**
     * Try to extract the root common equity ticker from a derivative instrument symbol.
     */
    public function resolveUnderlyingEquitySymbol(string $symbol, ?string $companyName = null, ?array $profile = null): ?string
    {
        $sym = strtoupper(trim($symbol));
        if (isset(self::KNOWN_DEBT_MAPPINGS[$sym])) {
            return self::KNOWN_DEBT_MAPPINGS[$sym]['equity'];
        }

        // Hyphen or dot separated derivative suffix: e.g. BAC-PK -> BAC, BW.PRA -> BW, XYZ-WS -> XYZ
        if (preg_match('/^([A-Z]{1,5})[-\.\/\^](PR|PFD|P|WS|WT|RT|R|U|UN|NT|N)[A-Z0-9]?$/', $sym, $m)) {
            return $m[1];
        }

        // Hyphen series suffix: e.g. BW-A -> BW
        if (preg_match('/^([A-Z]{1,5})-[A-Z0-9]$/', $sym, $m)) {
            return $m[1];
        }

        // Ticker with space: e.g. BW PRA -> BW
        if (preg_match('/^([A-Z]{1,5})\s+(PR|PFD|P)\s*[A-Z0-9]?$/', $sym, $m)) {
            return $m[1];
        }

        // 5-letter NASDAQ symbols ending with derivative suffix ONLY if confirmed warrant/right/unit
        if (strlen($sym) === 5) {
            $name = trim((string) ($companyName ?: ($profile['company_name'] ?? $profile['name'] ?? '')));
            $type = strtolower(trim((string) ($profile['type'] ?? $profile['security_type'] ?? '')));
            $suffix = substr($sym, 4, 1);
            if (($suffix === 'W' && $this->matchesWarrantPatterns($sym, $name, $type))
                || ($suffix === 'R' && $this->matchesRightsPatterns($sym, $name, $type))
                || ($suffix === 'U' && $this->matchesUnitPatterns($sym, $name, $type))
            ) {
                return substr($sym, 0, 4);
            }
        }

        return null;
    }

    /**
     * Determine whether the company name resembles common corporate equity.
     */
    public function looksLikeCommonEquityName(string $name): bool
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return false;
        }

        return (bool) preg_match('/\b(Inc\.?|Corp\.?|Corporation|Company|Co\.?|Ltd\.?|Limited|LLC|L\.L\.C\.?|LP|L\.P\.?|Plc\.?|PLC|S\.A\.?|NV|N\.V\.?|AG|SE|Holdings?|Enterprises?|Group|Technologies|Therapeutics|Pharmaceuticals|Bank|Bancorp|Financial|Capital)\b/i', $trimmed);
    }

    /**
     * Check if security exhibits debt, note, debenture, or bond characteristics.
     */
    protected function matchesDebtPatterns(string $symbol, string $name, string $type): bool
    {
        if (in_array($type, ['bond', 'bonds', 'notes', 'note', 'debt', 'debenture', 'debentures', 'corporate bond'], true)) {
            return true;
        }

        // Symbol patterns for notes or debt (e.g. .NT, -NT, .N, -N)
        if (preg_match('/^[A-Z]{1,5}[-\.\/\^](NT|N)$/i', $symbol)) {
            return true;
        }

        // Check for debt keywords and coupon/maturity patterns in listing name ONLY
        if ($name !== '') {
            if (preg_match('/\b(\d+(\.\d+)?%\s*(Senior|Subordinated|Convertible|Perpetual)?\s*(Notes?|Bonds?|Debentures?)|Senior Notes?|Subordinated Notes?|Convertible Notes?|Corporate Bond|Debentures?|Baby Bonds?|Notes?\s+due\s+\d{2,4}|Bonds?\s+due\s+\d{2,4}|Debentures?\s+due\s+\d{2,4}|\bDue\s+\d{1,2}\/\d{1,2}\/\d{2,4}|\bDue\s+20\d\d)\b/i', $name)) {
                return true;
            }

            // Bond fractional rate with maturity date (e.g. "BW 6 1/2 12/31/26")
            if (preg_match('/\b\d+\s+\d+\/\d+\s+\d{1,2}\/\d{1,2}\/\d{2,4}\b/', $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if security exhibits preferred share characteristics.
     */
    protected function matchesPreferredPatterns(string $symbol, string $name, string $type): bool
    {
        if (in_array($type, ['preferred', 'preferred stock', 'preferred shares', 'pfd'], true)) {
            return true;
        }

        // Symbol patterns: e.g. BW-PRA, BW.PR.A, BAC-PK, BAC.PRK, MET-PA, BW PRA, XYZpA
        // NOTE: Generic SYMBOL-X like BRK-A or BF-B are Class A/B common stock, NOT preferred.
        if (preg_match('/^[A-Z]{1,5}[-\.\/\^](PR|PFD)[A-Z0-9]?$/i', $symbol)) {
            return true;
        }
        if (preg_match('/^[A-Z]{1,5}[-\.\/\^]P[A-Z]$/i', $symbol)) {
            return true;
        }
        if (preg_match('/^[A-Z]{1,5}\s+(PR|PFD)\s*[A-Z0-9]?$/i', $symbol)) {
            return true;
        }
        if (preg_match('/^[A-Z]{1,5}p[A-Z]$/', $symbol)) {
            return true;
        }

        // Name patterns: explicitly match preferred share terminology while
        // avoiding legitimate company names like "Preferred Bank".
        if ($name !== '' && preg_match('/\b(Preferred Stock|Preferred Shares?|Series\s+[A-Z0-9]+\s+Preferred|Cumulative\s+Preferred|Perpetual\s+Preferred|Depositary\s+Shares?.*Preferred|Dep\.?\s*Shares?.*Preferred|\b\d+(\.\d+)?%\s*(Series\s+[A-Z0-9]+\s+)?Preferred|Pfd\s+Stock|Pfd\s+Shares?|\bPfd\b)\b/i', $name)) {
            return true;
        }

        return false;
    }

    /**
     * Check if security exhibits warrant characteristics.
     */
    protected function matchesWarrantPatterns(string $symbol, string $name, string $type): bool
    {
        if (in_array($type, ['warrant', 'warrants'], true)) {
            return true;
        }

        // Explicit derivative delimiter patterns: e.g. XYZ.WS, XYZ-WT, XYZ+
        if (preg_match('/^[A-Z]{1,5}[-\.\/\^](WS|WT)$/i', $symbol) || str_ends_with($symbol, '+')) {
            return true;
        }

        // Name patterns (never business description)
        if ($name !== '' && preg_match('/\b(Warrants?|WTs?)\b/i', $name)) {
            return true;
        }

        return false;
    }

    /**
     * Check if security exhibits rights characteristics.
     */
    protected function matchesRightsPatterns(string $symbol, string $name, string $type): bool
    {
        if (in_array($type, ['right', 'rights'], true)) {
            return true;
        }

        if (preg_match('/^[A-Z]{1,5}[-\.\/\^](RT|R)$/i', $symbol)) {
            return true;
        }

        if ($name !== '' && preg_match('/\b(Subscription Rights?|Rights?\s+Expiring)\b/i', $name)) {
            return true;
        }

        return false;
    }

    /**
     * Check if security exhibits unit characteristics.
     */
    protected function matchesUnitPatterns(string $symbol, string $name, string $type): bool
    {
        if (in_array($type, ['unit', 'units'], true)) {
            return true;
        }

        if (preg_match('/^[A-Z]{1,5}[-\.\/\^](UN|U)$/i', $symbol)) {
            return true;
        }

        if ($name !== '' && preg_match('/\b(Units?\s+consisting|Acquisition\s+Corp\.?\s+Units?|\bUnits\b)\b/i', $name)) {
            return true;
        }

        return false;
    }

    /**
     * Check if security exhibits Fund, ETF, ETN, or Trust characteristics.
     *
     * @param  array<string, mixed>  $profile
     */
    protected function matchesFundOrEtfPatterns(string $symbol, string $name, string $type, array $profile): bool
    {
        $isEtf = $profile['is_etf'] ?? $profile['isEtf'] ?? null;
        if ($isEtf !== null && filter_var($isEtf, FILTER_VALIDATE_BOOLEAN) === true) {
            return true;
        }

        $isFund = $profile['is_fund'] ?? $profile['isFund'] ?? null;
        if ($isFund !== null && filter_var($isFund, FILTER_VALIDATE_BOOLEAN) === true) {
            return true;
        }

        if (in_array($type, ['etf', 'fund', 'etn', 'mutual_fund', 'closed_end_fund', 'trust'], true)) {
            return true;
        }

        if ($name !== '' && preg_match('/\b(ETF|Exchange[- ]Traded Fund|ETN|Exchange[- ]Traded Note|Closed[- ]End Fund|Mutual Fund|Index Fund)\b/i', $name)) {
            return true;
        }

        return false;
    }
}
