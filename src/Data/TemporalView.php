<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class TemporalView
{
    public function __construct(
        public ?CarbonImmutable $eventThrough = null,
        public ?CarbonImmutable $accountingThrough = null,
        public ?CarbonImmutable $recordedThrough = null,
    ) {}

    public static function current(): self
    {
        return new self;
    }

    public static function eventAsOf(CarbonInterface $eventThrough, ?CarbonInterface $knownAt = null): self
    {
        return new self(
            eventThrough: CarbonImmutable::instance($eventThrough),
            recordedThrough: $knownAt === null ? null : CarbonImmutable::instance($knownAt),
        );
    }

    public static function accountingAsOf(CarbonInterface $accountingThrough, ?CarbonInterface $knownAt = null): self
    {
        return new self(
            accountingThrough: CarbonImmutable::instance($accountingThrough),
            recordedThrough: $knownAt === null ? null : CarbonImmutable::instance($knownAt),
        );
    }

    public function knownAt(CarbonInterface $recordedThrough): self
    {
        return new self(
            eventThrough: $this->eventThrough,
            accountingThrough: $this->accountingThrough,
            recordedThrough: CarbonImmutable::instance($recordedThrough),
        );
    }
}
