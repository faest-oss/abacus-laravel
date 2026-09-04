<?php

declare(strict_types=1);

use Faest\Abacus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class)->in(__DIR__);

uses(RefreshDatabase::class)->in(__DIR__.'/Feature');
