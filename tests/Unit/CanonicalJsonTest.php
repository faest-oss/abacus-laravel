<?php

declare(strict_types=1);

use Faest\Abacus\Support\CanonicalJson;
use Faest\Abacus\Support\PayloadFingerprint;

it('canonicalizes associative keys recursively while retaining lists and scalar types', function () {
    $left = [
        'z' => ['second' => true, 'first' => null],
        'a' => [['b' => 2, 'a' => 1], 'unchanged'],
    ];
    $right = [
        'a' => [['a' => 1, 'b' => 2], 'unchanged'],
        'z' => ['first' => null, 'second' => true],
    ];

    expect(CanonicalJson::normalize($left))->toBe($right)
        ->and(PayloadFingerprint::canonicalize($left))->toBe(PayloadFingerprint::canonicalize($right))
        ->and(PayloadFingerprint::canonicalize(['amount' => 100]))
        ->not->toBe(PayloadFingerprint::canonicalize(['amount' => 100.0]))
        ->and(CanonicalJson::normalize(['values' => [3, 1, 2]]))
        ->toBe(['values' => [3, 1, 2]]);
});

it('rejects unsupported and non-finite values', function () {
    $resource = fopen('php://memory', 'r');

    foreach ([fn () => null, new stdClass, $resource, INF, NAN] as $value) {
        expect(fn () => CanonicalJson::normalize(['value' => $value]))->toThrow(Exception::class);
    }

    fclose($resource);
});
