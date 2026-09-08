<?php

declare(strict_types=1);

use Faest\Abacus\Tests\Fixtures\TwoProcessHarness;

it('runs captured closures in separate processes', function () {
    $value = 'captured value';
    $task = static function () use ($value): array {
        return ['value' => $value, 'pid' => getmypid()];
    };

    $results = TwoProcessHarness::run($task, $task);

    expect(array_column($results, 'value'))->toBe([$value, $value])
        ->and($results[0]['pid'])->not->toBe(getmypid())
        ->and($results[1]['pid'])->not->toBe(getmypid())
        ->and($results[0]['pid'])->not->toBe($results[1]['pid']);
});
