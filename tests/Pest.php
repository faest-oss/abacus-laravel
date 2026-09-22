<?php

declare(strict_types=1);

use Faest\Abacus\Tests\Support\RefreshDatabase;
use Faest\Abacus\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

uses(RefreshDatabase::class)->in(__DIR__.'/Feature');
