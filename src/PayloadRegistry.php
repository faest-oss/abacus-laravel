<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use InvalidArgumentException;

final class PayloadRegistry
{
    /** @var array<string, class-string<LedgerPayload>> */
    private array $registry = [];
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function register(string $type, string $payloadClass): self
    {
        if (! is_subclass_of($payloadClass, LedgerPayload::class)) {
            throw new InvalidArgumentException("Class {$payloadClass} must implement LedgerPayload");
        }

        $this->registry[$type] = $payloadClass;
        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function deserialize(string $type, array $data): LedgerPayload
    {
        if (isset($this->registry[$type])) {
            $class = $this->registry[$type];

            if (is_subclass_of($class, DeserializablePayload::class)) {
                /** @var class-string<DeserializablePayload> $class */
                return $class::fromPayload($data);
            }
        }

        return GenericPayload::make($type, $data);
    }
}
