<?php

use App\Services\Earnings\EpsSurpriseSanity;

it('rejects the implausible GPUS estimate, with and without a price', function () {
    expect(EpsSurpriseSanity::calculatePercent(-0.30, -28540.2169, 0.159))
        ->toBeNull();
    expect(EpsSurpriseSanity::calculatePercent(-0.30, -28540.2169))
        ->toBeNull();
});

it('retains valid beats and misses including loss-making companies', function () {
    expect(EpsSurpriseSanity::calculatePercent(0.50, 0.25))->toBe(100.0)
        ->and(round(EpsSurpriseSanity::calculatePercent(0.94, 0.50), 4))->toBe(88.0)
        ->and(EpsSurpriseSanity::calculatePercent(-0.25, -0.50))->toBe(50.0)
        ->and(EpsSurpriseSanity::calculatePercent(-1.0, -0.50))->toBe(-100.0);
});

it('rejects null, zero, malformed and non-finite figures', function () {
    expect(EpsSurpriseSanity::calculatePercent(null, 1.0))->toBeNull()
        ->and(EpsSurpriseSanity::calculatePercent(1.0, 0.0))->toBeNull()
        ->and(EpsSurpriseSanity::calculatePercent(1.0, 0.01))->toBeNull()
        ->and(EpsSurpriseSanity::calculatePercent(1.0, 'error'))->toBeNull()
        ->and(EpsSurpriseSanity::calculatePercent(INF, 1.0))->toBeNull();
});

it('supports ordinary high-priced shares when a plausible price is supplied', function () {
    expect(EpsSurpriseSanity::calculatePercent(2000.0, 1000.0, 300000.0))
        ->toBe(100.0);
});

it('retains the real arithmetic for very high percentages but flags them for verification', function () {
    expect(round(EpsSurpriseSanity::calculatePercent(5.11, 0.02), 2))->toBe(25450.0)
        ->and(EpsSurpriseSanity::requiresReview(5.11, 0.02))->toBeTrue()
        ->and(EpsSurpriseSanity::requiresReview(2.80, -0.02))->toBeTrue()
        ->and(EpsSurpriseSanity::requiresReview(45.95, -1.32))->toBeTrue()
        ->and(EpsSurpriseSanity::requiresReview(2.45, 0.0695))->toBeTrue()
        ->and(EpsSurpriseSanity::requiresReview(0.50, 0.25))->toBeFalse()
        ->and(EpsSurpriseSanity::requiresReview(-0.30, -28540.2169))->toBeTrue();
});
