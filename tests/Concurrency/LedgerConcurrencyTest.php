<?php

declare(strict_types=1);

use function PHPUnit\Framework\assertEquals;

beforeEach(function () {
    if (config('database.default') === 'sqlite') {
        return $this->markTestSkipped('Concurrency suite cannot run on sqlite');
    }

    $this->artisan('migrate:fresh');
});

test('todo', function () {
    assertEquals(1, 1);
})->todo();
