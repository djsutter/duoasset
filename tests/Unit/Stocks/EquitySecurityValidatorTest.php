<?php

use App\Services\Stocks\EquitySecurityValidator;
use App\Services\Stocks\EquityValidationResult;

uses(Tests\TestCase::class);

test('EquitySecurityValidator excludes BWNB as redeemed bond and identifies BW as underlying stock', function () {
    $validator = app(EquitySecurityValidator::class);

    $result = $validator->validate('BWNB', 'Babcock & Wilcox Enterprises Inc. 6.50% Senior Notes due 2026');

    expect($result->isValid())->toBeFalse()
        ->and($result->isCommonEquity())->toBeFalse()
        ->and($result->instrumentType())->toBe(EquityValidationResult::TYPE_REDEEMED_BOND)
        ->and($result->underlyingSymbol())->toBe('BW')
        ->and($result->reason())->toContain('BWNB is a redeemed bond')
        ->and($result->reason())->toContain('actual common equity is BW');

    expect($validator->isCommonEquity('BWNB'))->toBeFalse()
        ->and($validator->isEligibleEquity('BWNB'))->toBeFalse();
});

test('EquitySecurityValidator excludes BWSN as senior notes and identifies BW as underlying stock', function () {
    $validator = app(EquitySecurityValidator::class);

    $result = $validator->validate('BWSN', 'Babcock & Wilcox Enterprises Inc. 8.125% Senior Notes due 2026');

    expect($result->isValid())->toBeFalse()
        ->and($result->instrumentType())->toBe(EquityValidationResult::TYPE_BOND)
        ->and($result->underlyingSymbol())->toBe('BW')
        ->and($result->reason())->toContain('actual common equity is BW');
});

test('EquitySecurityValidator validates actual common stock BW and other equities', function () {
    $validator = app(EquitySecurityValidator::class);

    // With affirmative profile evidence (e.g. type => stock)
    $bw = $validator->validate('BW', 'Babcock & Wilcox Enterprises, Inc.', ['type' => 'stock']);
    expect($bw->isValid())->toBeTrue()
        ->and($bw->isCommonEquity())->toBeTrue()
        ->and($bw->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY)
        ->and($bw->underlyingSymbol())->toBe('BW')
        ->and($bw->reason())->toBeNull();

    $aapl = $validator->validate('AAPL', 'Apple Inc.', ['type' => 'stock']);
    expect($aapl->isValid())->toBeTrue()
        ->and($aapl->isCommonEquity())->toBeTrue();

    $msft = $validator->validate('MSFT', 'Microsoft Corporation', ['type' => 'common stock']);
    expect($msft->isValid())->toBeTrue()
        ->and($msft->isCommonEquity())->toBeTrue();

    $brk = $validator->validate('BRK.A', 'Berkshire Hathaway Inc. Class A Common Stock');
    expect($brk->isValid())->toBeTrue()
        ->and($brk->isCommonEquity())->toBeTrue();

    // Without affirmative evidence, securities remain provisionally eligible under insufficient_metadata
    $provisional = $validator->validate('BW', 'Babcock & Wilcox Enterprises, Inc.');
    expect($provisional->isValid())->toBeTrue()
        ->and($provisional->isCommonEquity())->toBeFalse()
        ->and($provisional->isInsufficientMetadata())->toBeTrue()
        ->and($validator->isEligibleEquity('BW'))->toBeTrue();
});

test('EquitySecurityValidator does not misidentify Preferred Bank as preferred stock', function () {
    $validator = app(EquitySecurityValidator::class);

    // With affirmative type => stock
    $result = $validator->validate('PFBC', 'Preferred Bank', ['type' => 'stock']);
    expect($result->isValid())->toBeTrue()
        ->and($result->isCommonEquity())->toBeTrue()
        ->and($result->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);

    // Without affirmative evidence, it is not preferred stock and remains provisionally eligible
    $provisional = $validator->validate('PFBC', 'Preferred Bank');
    expect($provisional->isValid())->toBeTrue()
        ->and($provisional->isCommonEquity())->toBeFalse()
        ->and($provisional->instrumentType())->not->toBe(EquityValidationResult::TYPE_PREFERRED_STOCK)
        ->and($provisional->isInsufficientMetadata())->toBeTrue();
});

test('EquitySecurityValidator excludes preferred shares by symbol and company name', function () {
    $validator = app(EquitySecurityValidator::class);

    $p1 = $validator->validate('BW-A', 'Babcock & Wilcox Enterprises 7.75% Series A Cumulative Perpetual Preferred Stock');
    expect($p1->isValid())->toBeFalse()
        ->and($p1->instrumentType())->toBe(EquityValidationResult::TYPE_PREFERRED_STOCK)
        ->and($p1->underlyingSymbol())->toBe('BW');

    $p2 = $validator->validate('BW PRA', 'Babcock & Wilcox 7.75% Preferred');
    expect($p2->isValid())->toBeFalse()
        ->and($p2->instrumentType())->toBe(EquityValidationResult::TYPE_PREFERRED_STOCK)
        ->and($p2->underlyingSymbol())->toBe('BW');

    $p3 = $validator->validate('BAC-PK', 'Bank of America Corporation Depositary Shares Preferred');
    expect($p3->isValid())->toBeFalse()
        ->and($p3->instrumentType())->toBe(EquityValidationResult::TYPE_PREFERRED_STOCK)
        ->and($p3->underlyingSymbol())->toBe('BAC');

    $p4 = $validator->validate('BAC.PR.K', 'Bank of America Corp 5.875% Non-Cum Preferred Stock, Series K');
    expect($p4->isValid())->toBeFalse()
        ->and($p4->instrumentType())->toBe(EquityValidationResult::TYPE_PREFERRED_STOCK);
});

test('EquitySecurityValidator excludes bonds, notes, and debt instruments', function () {
    $validator = app(EquitySecurityValidator::class);

    $b1 = $validator->validate('XYZ', 'ABC Corp 6.50% Senior Notes due 2028');
    expect($b1->isValid())->toBeFalse()
        ->and($b1->instrumentType())->toBe(EquityValidationResult::TYPE_BOND);

    $b2 = $validator->validate('XYZ', 'XYZ Corp - Corporate Bond/Note');
    expect($b2->isValid())->toBeFalse()
        ->and($b2->instrumentType())->toBe(EquityValidationResult::TYPE_BOND);

    $b3 = $validator->validate('BW.NT', 'Babcock & Wilcox 6.5% Notes');
    expect($b3->isValid())->toBeFalse()
        ->and($b3->instrumentType())->toBe(EquityValidationResult::TYPE_BOND);

    $b4 = $validator->validate('XYZ', 'BABCOCK & WILCOX ENTERPR - BW 6 1/2 12/31/26');
    expect($b4->isValid())->toBeFalse()
        ->and($b4->instrumentType())->toBe(EquityValidationResult::TYPE_BOND);
});

test('EquitySecurityValidator excludes inactive and redeemed securities', function () {
    $validator = app(EquitySecurityValidator::class);

    $i1 = $validator->validate('DELIST', 'Some Company', ['is_actively_trading' => false]);
    expect($i1->isValid())->toBeFalse()
        ->and($i1->instrumentType())->toBe(EquityValidationResult::TYPE_INACTIVE_SECURITY)
        ->and($i1->reason())->toContain('not actively trading');

    $i2 = $validator->validate('XYZ', 'XYZ Corp 5% Notes (Redeemed)');
    expect($i2->isValid())->toBeFalse()
        ->and($i2->instrumentType())->toBe(EquityValidationResult::TYPE_REDEEMED_BOND)
        ->and($i2->reason())->toContain('redeemed bond');

    $i3 = $validator->validate('OLD', 'Old Company (Liquidated)');
    expect($i3->isValid())->toBeFalse()
        ->and($i3->instrumentType())->toBe(EquityValidationResult::TYPE_INACTIVE_SECURITY);
});

test('EquitySecurityValidator excludes warrants, rights, and units', function () {
    $validator = app(EquitySecurityValidator::class);

    $w1 = $validator->validate('LCIDW', 'Lucid Group, Inc. Warrants');
    expect($w1->isValid())->toBeFalse()
        ->and($w1->instrumentType())->toBe(EquityValidationResult::TYPE_WARRANT)
        ->and($w1->underlyingSymbol())->toBe('LCID');

    $w2 = $validator->validate('XYZ.WS', 'XYZ Corp Warrants Expiring 2028');
    expect($w2->isValid())->toBeFalse()
        ->and($w2->instrumentType())->toBe(EquityValidationResult::TYPE_WARRANT);

    $r1 = $validator->validate('XYZ.RT', 'XYZ Corp Subscription Rights');
    expect($r1->isValid())->toBeFalse()
        ->and($r1->instrumentType())->toBe(EquityValidationResult::TYPE_RIGHT);

    $u1 = $validator->validate('CLAYU', 'Clay Acquisition Corp Units');
    expect($u1->isValid())->toBeFalse()
        ->and($u1->instrumentType())->toBe(EquityValidationResult::TYPE_UNIT);
});

test('EquitySecurityValidator excludes ETFs and funds', function () {
    $validator = app(EquitySecurityValidator::class);

    $etf1 = $validator->validate('SPY', 'SPDR S&P 500 ETF Trust', ['is_etf' => true]);
    expect($etf1->isValid())->toBeFalse()
        ->and($etf1->instrumentType())->toBe(EquityValidationResult::TYPE_FUND_OR_ETF);

    $fund1 = $validator->validate('MUTL', 'Global Equity Mutual Fund', ['is_fund' => true]);
    expect($fund1->isValid())->toBeFalse()
        ->and($fund1->instrumentType())->toBe(EquityValidationResult::TYPE_FUND_OR_ETF);

    $cef = $validator->validate('CEF', 'American Closed-End Fund');
    expect($cef->isValid())->toBeFalse()
        ->and($cef->instrumentType())->toBe(EquityValidationResult::TYPE_FUND_OR_ETF);
});

test('EquitySecurityValidator does not exclude common equity mentioning redeemed notes in its business description', function () {
    $validator = app(EquitySecurityValidator::class);

    $profile = [
        'is_actively_trading' => true,
        'is_etf' => false,
        'is_fund' => false,
        'type' => 'stock',
        'description' => 'Apple Inc. designs, manufactures, and markets smartphones. In 2021, the company redeemed senior notes due 2026 and issued 3.5% corporate bonds.',
    ];

    $result = $validator->validate('AAPL', 'Apple Inc.', $profile);

    expect($result->isValid())->toBeTrue()
        ->and($result->isCommonEquity())->toBeTrue()
        ->and($result->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY)
        ->and($result->reason())->toBeNull();
});

test('EquitySecurityValidator does not exclude common equity mentioning warrants or preferred shares in business description', function () {
    $validator = app(EquitySecurityValidator::class);

    $profile = [
        'is_actively_trading' => true,
        'is_etf' => false,
        'is_fund' => false,
        'type' => 'stock',
        'description' => 'Acme Corporation is a technology provider. The company completed a private financing involving warrants and convertible preferred stock series A.',
    ];

    $result = $validator->validate('ACME', 'Acme Corporation', $profile);

    expect($result->isValid())->toBeTrue()
        ->and($result->isCommonEquity())->toBeTrue()
        ->and($result->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY)
        ->and($result->reason())->toBeNull();
});

test('EquitySecurityValidator does not treat five-character tickers ending in W, R, or U as warrants, rights, or units', function () {
    $validator = app(EquitySecurityValidator::class);

    // With affirmative structured type
    $arrow = $validator->validate('ARROW', 'Arrow Financial Corporation', ['is_actively_trading' => true, 'type' => 'stock', 'is_etf' => false, 'is_fund' => false]);
    expect($arrow->isValid())->toBeTrue()
        ->and($arrow->isCommonEquity())->toBeTrue()
        ->and($arrow->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);

    $amplr = $validator->validate('AMPLR', 'Amplitech Group Inc.', ['is_actively_trading' => true, 'type' => 'stock', 'is_etf' => false, 'is_fund' => false]);
    expect($amplr->isValid())->toBeTrue()
        ->and($amplr->isCommonEquity())->toBeTrue()
        ->and($amplr->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);

    $baeru = $validator->validate('BAERU', 'Baer Holdings Corp.', ['is_actively_trading' => true, 'type' => 'stock', 'is_etf' => false, 'is_fund' => false]);
    expect($baeru->isValid())->toBeTrue()
        ->and($baeru->isCommonEquity())->toBeTrue()
        ->and($baeru->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);

    // Without affirmative structured type, actively trading tickers ending in W/R/U are not misclassified as warrants/rights/units, but remain provisionally eligible
    $arrowProvisional = $validator->validate('ARROW', 'Arrow Financial Corporation', ['is_actively_trading' => true, 'is_etf' => false, 'is_fund' => false]);
    expect($arrowProvisional->isValid())->toBeTrue()
        ->and($arrowProvisional->isCommonEquity())->toBeFalse()
        ->and($arrowProvisional->instrumentType())->toBe(EquityValidationResult::TYPE_INSUFFICIENT_METADATA)
        ->and($arrowProvisional->isInsufficientMetadata())->toBeTrue();
});

test('EquitySecurityValidator does not automatically classify SYMBOL-X share classes as preferred stock', function () {
    $validator = app(EquitySecurityValidator::class);

    $brk = $validator->validate('BRK-A', 'Berkshire Hathaway Inc. Class A', ['type' => 'stock']);
    expect($brk->isValid())->toBeTrue()
        ->and($brk->isCommonEquity())->toBeTrue()
        ->and($brk->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);

    $bf = $validator->validate('BF-B', 'Brown-Forman Corporation Class B', ['type' => 'stock']);
    expect($bf->isValid())->toBeTrue()
        ->and($bf->isCommonEquity())->toBeTrue()
        ->and($bf->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);

    // Without affirmative evidence, they are not misclassified as preferred stock and remain provisionally eligible
    $brkProvisional = $validator->validate('BRK-A', 'Berkshire Hathaway Inc. Class A');
    expect($brkProvisional->isValid())->toBeTrue()
        ->and($brkProvisional->isCommonEquity())->toBeFalse()
        ->and($brkProvisional->instrumentType())->not->toBe(EquityValidationResult::TYPE_PREFERRED_STOCK)
        ->and($brkProvisional->isInsufficientMetadata())->toBeTrue();
});

test('EquitySecurityValidator does not classify active non-equity instruments as confirmed common equity', function () {
    $validator = app(EquitySecurityValidator::class);

    // Active debt
    $activeBond = $validator->validate('CORPB', 'Corp Senior Notes 6.5%', [
        'is_actively_trading' => true,
        'type' => 'bond',
    ]);
    expect($activeBond->isValid())->toBeFalse()
        ->and($activeBond->isCommonEquity())->toBeFalse()
        ->and($activeBond->isConfirmedNonEquity())->toBeTrue()
        ->and($activeBond->instrumentType())->toBe(EquityValidationResult::TYPE_BOND);

    // Active preferred
    $activePref = $validator->validate('XYZ-P', 'XYZ Corp 7% Preferred Stock', [
        'is_actively_trading' => true,
        'type' => 'preferred stock',
    ]);
    expect($activePref->isValid())->toBeFalse()
        ->and($activePref->isCommonEquity())->toBeFalse()
        ->and($activePref->isConfirmedNonEquity())->toBeTrue()
        ->and($activePref->instrumentType())->toBe(EquityValidationResult::TYPE_PREFERRED_STOCK);

    // Active ETF
    $activeEtf = $validator->validate('VOO', 'Vanguard S&P 500 ETF', [
        'is_actively_trading' => true,
        'is_etf' => true,
        'is_fund' => false,
    ]);
    expect($activeEtf->isValid())->toBeFalse()
        ->and($activeEtf->isCommonEquity())->toBeFalse()
        ->and($activeEtf->isConfirmedNonEquity())->toBeTrue()
        ->and($activeEtf->instrumentType())->toBe(EquityValidationResult::TYPE_FUND_OR_ETF);

    // Active corporate name without affirmative evidence returns insufficient_metadata, not confirmed common equity
    $activeUnknown = $validator->validate('XYZ', 'XYZ Corporation', [
        'is_actively_trading' => true,
        'is_etf' => false,
        'is_fund' => false,
        'type' => null,
    ]);
    expect($activeUnknown->isValid())->toBeTrue()
        ->and($activeUnknown->isCommonEquity())->toBeFalse()
        ->and($activeUnknown->isInsufficientMetadata())->toBeTrue()
        ->and($activeUnknown->status())->toBe(EquityValidationResult::STATUS_INSUFFICIENT_METADATA);
});

test('EquitySecurityValidator distinguishes unknown instruments with incomplete metadata and applies conservative fallback', function () {
    $validator = app(EquitySecurityValidator::class);

    $unknown = $validator->validate('UNKNW', null, []);

    expect($unknown->isValid())->toBeTrue()
        ->and($unknown->isCommonEquity())->toBeFalse()
        ->and($unknown->isInsufficientMetadata())->toBeTrue()
        ->and($unknown->instrumentType())->toBe(EquityValidationResult::TYPE_INSUFFICIENT_METADATA)
        ->and($unknown->status())->toBe(EquityValidationResult::STATUS_INSUFFICIENT_METADATA)
        ->and($validator->isCommonEquity('UNKNW'))->toBeFalse()
        ->and($validator->isEligibleEquity('UNKNW'))->toBeTrue();
});

test('BuySetupConfigService respects BUY_SETUP_EQUITY_VALIDATION_ENABLED config and env precedence', function () {
    $configService = app(\App\Services\Stocks\BuySetupConfigService::class);

    config(['market_data.buy_setup_scanner.equity_validation_enabled' => false]);
    expect($configService->isEquityValidationEnabled())->toBeFalse();

    config(['market_data.buy_setup_scanner.equity_validation_enabled' => true]);
    expect($configService->isEquityValidationEnabled())->toBeTrue();
});

test('EquitySecurityValidator does not exclude symbols by arbitrary patterns like TEST or ZZZ', function () {
    $validator = app(EquitySecurityValidator::class);

    // TEST symbol with common equity metadata is valid
    $testSym = $validator->validate('TEST', 'Test Equity Corp.', ['type' => 'stock', 'is_actively_trading' => true]);
    expect($testSym->isValid())->toBeTrue()
        ->and($testSym->isCommonEquity())->toBeTrue()
        ->and($testSym->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);

    // ZZZ symbol with common equity metadata is valid
    $zzzSym = $validator->validate('ZZZ', 'ZZZ Holdings Inc.', ['type' => 'stock', 'is_actively_trading' => true]);
    expect($zzzSym->isValid())->toBeTrue()
        ->and($zzzSym->isCommonEquity())->toBeTrue()
        ->and($zzzSym->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);

    // TEST symbol with unknown metadata is provisionally eligible
    $testProvisional = $validator->validate('TEST', 'Test Corp.');
    expect($testProvisional->isValid())->toBeTrue()
        ->and($testProvisional->isInsufficientMetadata())->toBeTrue();
});

test('EquitySecurityValidator confirms ADRs as eligible equity instruments', function () {
    $validator = app(EquitySecurityValidator::class);

    // ADR via structured type
    $adrType = $validator->validate('TSM', 'Taiwan Semiconductor Manufacturing Co Ltd', ['type' => 'adr', 'is_actively_trading' => true]);
    expect($adrType->isValid())->toBeTrue()
        ->and($adrType->isCommonEquity())->toBeTrue()
        ->and($adrType->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY)
        ->and($validator->isEligibleEquity('TSM'))->toBeTrue();

    // ADR via is_adr flag
    $adrFlag = $validator->validate('BABA', 'Alibaba Group Holding Ltd', ['is_adr' => true, 'is_actively_trading' => true]);
    expect($adrFlag->isValid())->toBeTrue()
        ->and($adrFlag->isCommonEquity())->toBeTrue()
        ->and($adrFlag->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);

    // ADR via listing name
    $adrName = $validator->validate('NVO', 'Novo Nordisk A/S ADR', ['is_actively_trading' => true]);
    expect($adrName->isValid())->toBeTrue()
        ->and($adrName->isCommonEquity())->toBeTrue()
        ->and($adrName->instrumentType())->toBe(EquityValidationResult::TYPE_COMMON_EQUITY);
});
