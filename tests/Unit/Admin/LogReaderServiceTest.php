<?php

use App\Services\Admin\LogReaderService;

test('log reader service correctly categorizes log entries into domain types', function () {
    $service = new LogReaderService();

    // Notifications / Email
    expect($service->categorize('buy_setup.notifying_user'))->toBe(LogReaderService::TYPE_NOTIFICATIONS);
    expect($service->categorize('buy_setup.user_notified'))->toBe(LogReaderService::TYPE_NOTIFICATIONS);
    expect($service->categorize('buy_setup.notify_failed'))->toBe(LogReaderService::TYPE_NOTIFICATIONS);
    expect($service->categorize('buy_setup.extra_email_notified'))->toBe(LogReaderService::TYPE_NOTIFICATIONS);
    expect($service->categorize('earnings.notifying_extra_email'))->toBe(LogReaderService::TYPE_NOTIFICATIONS);
    expect($service->categorize('earnings.notify_failed'))->toBe(LogReaderService::TYPE_NOTIFICATIONS);
    expect($service->categorize('revision.user_notifications_completed'))->toBe(LogReaderService::TYPE_NOTIFICATIONS);
    expect($service->categorize('alerts.notifying_watchlist_user'))->toBe(LogReaderService::TYPE_NOTIFICATIONS);
    expect($service->categorize('Failed sending email to recipient'))->toBe(LogReaderService::TYPE_NOTIFICATIONS);

    // Setup Processing
    expect($service->categorize('buy_setup.rejected'))->toBe(LogReaderService::TYPE_SETUP);
    expect($service->categorize('buy_setup.evaluated'))->toBe(LogReaderService::TYPE_SETUP);
    expect($service->categorize('buy_setup.evaluate_failed'))->toBe(LogReaderService::TYPE_SETUP);
    expect($service->categorize('stocks:scan-buy-setups processed 50 symbols'))->toBe(LogReaderService::TYPE_SETUP);

    // EPS / Earnings Processing
    expect($service->categorize('earnings.surprises_failed'))->toBe(LogReaderService::TYPE_EPS);
    expect($service->categorize('earnings.invalid_eps'))->toBe(LogReaderService::TYPE_EPS);
    expect($service->categorize('eps_revision.fetch_failed'))->toBe(LogReaderService::TYPE_EPS);
    expect($service->categorize('earnings:scan-revisions completed'))->toBe(LogReaderService::TYPE_EPS);

    // System / General
    expect($service->categorize('Database connection failed'))->toBe(LogReaderService::TYPE_SYSTEM);
    expect($service->categorize('Cache key invalidated'))->toBe(LogReaderService::TYPE_SYSTEM);
});

test('log reader service parses and filters log content with multiline stack traces and json context', function () {
    $service = new LogReaderService();

    $logContent = <<<LOG
[2026-10-24 06:10:00] local.INFO: Application booted successfully
[2026-10-24 06:15:00] local.INFO: buy_setup.notifying_user {"alert_id":10,"user_id":1,"email":"trader@test.com"}
[2026-10-24 06:16:00] local.ERROR: buy_setup.notify_failed {"alert_id":10,"error":"SMTP timeout"}
#0 /app/Mail.php:24
#1 /app/Job.php:50
[2026-10-24 06:20:00] local.INFO: buy_setup.rejected {"symbol":"FEO","exchange":"TSXV","market_cap":10000000,"reason":"market cap below setup minimum (50000000)"}
[2026-10-24 06:21:00] local.INFO: buy_setup.evaluated {"symbol":"AAPL","exchange":"NASDAQ","matches":[{"setup_type":"heartbeat_consolidation_spike","score":88}]}
[2026-10-24 06:22:00] local.WARNING: earnings.invalid_eps {"symbol":"MSFT","reason":"missing actual eps"}
LOG;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_log_');
    file_put_contents($tempFile, $logContent);

    try {
        // Unfiltered fetch
        $all = $service->getLogs($tempFile, LogReaderService::TYPE_ALL, LogReaderService::LEVEL_ALL);
        expect($all['summary']['total_scanned'])->toBe(6);
        expect($all['summary']['notifications'])->toBe(2);
        expect($all['summary']['setup'])->toBe(2);
        expect($all['summary']['eps'])->toBe(1);
        expect($all['summary']['system'])->toBe(1);
        expect($all['summary']['errors'])->toBe(1);
        expect($all['summary']['warnings'])->toBe(1);
        expect($all['summary']['info'])->toBe(4);

        // Check newest-first order
        expect($all['entries'][0]['message'])->toBe('earnings.invalid_eps');

        // Check multiline stack trace captured
        $notifyFailedEntry = collect($all['entries'])->firstWhere('message', 'buy_setup.notify_failed');
        expect($notifyFailedEntry['stack_trace'])->toContain('#0 /app/Mail.php:24');

        // Check symbol extraction
        $rejectedEntry = collect($all['entries'])->firstWhere('message', 'buy_setup.rejected');
        expect($rejectedEntry['symbol'])->toBe('FEO');
        expect($rejectedEntry['context']['reason'])->toContain('market cap below setup minimum');

        // Filter by Notifications
        $notifications = $service->getLogs($tempFile, LogReaderService::TYPE_NOTIFICATIONS);
        expect($notifications['entries'])->toHaveCount(2);

        // Filter by Setup
        $setupLogs = $service->getLogs($tempFile, LogReaderService::TYPE_SETUP);
        expect($setupLogs['entries'])->toHaveCount(2);

        // Filter by EPS
        $epsLogs = $service->getLogs($tempFile, LogReaderService::TYPE_EPS);
        expect($epsLogs['entries'])->toHaveCount(1);
        expect($epsLogs['entries'][0]['message'])->toBe('earnings.invalid_eps');

        // Filter by Level (errors only)
        $errors = $service->getLogs($tempFile, LogReaderService::TYPE_ALL, LogReaderService::LEVEL_ERROR);
        expect($errors['entries'])->toHaveCount(1);
        expect($errors['entries'][0]['message'])->toBe('buy_setup.notify_failed');

        // Search by keyword
        $searched = $service->getLogs($tempFile, LogReaderService::TYPE_ALL, LogReaderService::LEVEL_ALL, 'FEO');
        expect($searched['entries'])->toHaveCount(1);
        expect($searched['entries'][0]['symbol'])->toBe('FEO');
    } finally {
        @unlink($tempFile);
    }
});

test('log reader service gracefully handles non-existent or empty log files', function () {
    $service = new LogReaderService();
    $result = $service->getLogs('/path/to/non_existent_file_' . uniqid() . '.log');

    expect($result['file_exists'])->toBeFalse();
    expect($result['entries'])->toBeEmpty();
    expect($result['summary']['total_scanned'])->toBe(0);
});
