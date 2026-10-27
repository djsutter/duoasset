<?php

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

test('.env.example defines MAIL_STARTTLS_CERT_VERIFY defaulting to false', function () {
    $envExample = file_get_contents(base_path('.env.example'));

    expect($envExample)->toContain('MAIL_STARTTLS_CERT_VERIFY=false');
});

test('smtp mailer defaults to verify_peer false and disables ssl peer verification in socket stream', function () {
    config([
        'mail.mailers.smtp.verify_peer' => false,
    ]);

    app('mail.manager')->forgetMailers();
    $mailer = app('mail.manager')->mailer('smtp');
    $transport = $mailer->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(EsmtpTransport::class);

    $stream = $transport->getStream();
    expect($stream)->toBeInstanceOf(SocketStream::class);

    $streamOptions = $stream->getStreamOptions();
    expect($streamOptions)->toHaveKey('ssl')
        ->and($streamOptions['ssl']['verify_peer'])->toBeFalse()
        ->and($streamOptions['ssl']['verify_peer_name'])->toBeFalse();
});

test('enabling verify_peer preserves peer verification in socket stream', function () {
    config([
        'mail.mailers.smtp.verify_peer' => true,
    ]);

    app('mail.manager')->forgetMailers();
    $mailer = app('mail.manager')->mailer('smtp');
    $transport = $mailer->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(EsmtpTransport::class);

    $stream = $transport->getStream();
    expect($stream)->toBeInstanceOf(SocketStream::class);

    $streamOptions = $stream->getStreamOptions();
    expect($streamOptions['ssl']['verify_peer'] ?? null)->not()->toBeFalse()
        ->and($streamOptions['ssl']['verify_peer_name'] ?? null)->not()->toBeFalse();
});

test('mail config evaluates MAIL_STARTTLS_CERT_VERIFY environment values as robust booleans', function () {
    $falsyValues = ['false', 'FALSE', '0', 'off', 'no', ''];
    $truthyValues = ['true', 'TRUE', '1', 'on', 'yes'];

    foreach ($falsyValues as $val) {
        $parsed = filter_var($val, FILTER_VALIDATE_BOOL);
        expect($parsed)->toBeFalse("Failed asserting that '$val' evaluates to false");
    }

    foreach ($truthyValues as $val) {
        $parsed = filter_var($val, FILTER_VALIDATE_BOOL);
        expect($parsed)->toBeTrue("Failed asserting that '$val' evaluates to true");
    }
});

test('standard smtp transport settings are preserved in configuration', function () {
    $smtpConfig = config('mail.mailers.smtp');

    expect($smtpConfig)->toHaveKeys([
        'transport',
        'scheme',
        'url',
        'host',
        'port',
        'username',
        'password',
        'timeout',
        'local_domain',
        'verify_peer',
    ])
        ->and($smtpConfig['transport'])->toBe('smtp');
});

test('config/mail.php maps MAIL_STARTTLS_CERT_VERIFY env var to verify_peer', function () {
    $evalConfig = function (?string $envVal) {
        if ($envVal === null) {
            putenv('MAIL_STARTTLS_CERT_VERIFY');
            unset($_ENV['MAIL_STARTTLS_CERT_VERIFY'], $_SERVER['MAIL_STARTTLS_CERT_VERIFY']);
        } else {
            putenv("MAIL_STARTTLS_CERT_VERIFY={$envVal}");
            $_ENV['MAIL_STARTTLS_CERT_VERIFY'] = $envVal;
            $_SERVER['MAIL_STARTTLS_CERT_VERIFY'] = $envVal;
        }

        $mailConfig = require config_path('mail.php');

        return $mailConfig['mailers']['smtp']['verify_peer'];
    };

    try {
        expect($evalConfig(null))->toBeFalse()
            ->and($evalConfig('false'))->toBeFalse()
            ->and($evalConfig('0'))->toBeFalse()
            ->and($evalConfig('true'))->toBeTrue()
            ->and($evalConfig('1'))->toBeTrue();
    } finally {
        putenv('MAIL_STARTTLS_CERT_VERIFY=false');
        $_ENV['MAIL_STARTTLS_CERT_VERIFY'] = 'false';
        $_SERVER['MAIL_STARTTLS_CERT_VERIFY'] = 'false';
    }
});
