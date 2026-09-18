<?php

declare(strict_types=1);

use Faest\Abacus\Abacus;

it('resolves the singleton', function () {
    expect(app(Abacus::class))->toBeInstanceOf(Abacus::class);
});

it('returns the same instance from the container', function () {
    expect(app(Abacus::class))->toBe(app(Abacus::class));
});

it('merges the package config', function () {
    expect(config('abacus.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('abacus::messages.placeholder'))->toBe('Abacus placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('abacus::placeholder'))->toBeTrue();
});
