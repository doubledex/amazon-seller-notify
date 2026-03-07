<?php

use App\Services\Amazon\Support\AmazonRequestPolicy;

it('returns callback result for non-response values', function () {
    $policy = new AmazonRequestPolicy();

    $result = $policy->execute('test.op', fn () => ['ok' => true]);

    expect($result)->toBe(['ok' => true]);
});

it('retries thrown exceptions and eventually returns', function () {
    $policy = new AmazonRequestPolicy();

    $attempts = 0;
    $result = $policy->execute('test.exception', function () use (&$attempts) {
        $attempts++;
        if ($attempts < 2) {
            throw new RuntimeException('temporary');
        }

        return 'ok';
    }, maxAttempts: 3);

    expect($result)->toBe('ok')
        ->and($attempts)->toBe(2);
});
