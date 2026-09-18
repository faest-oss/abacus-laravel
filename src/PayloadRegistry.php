<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use InvalidArgumentException;

final class PayloadRegistry
{
    /** @var array<string, class-string<DeserializablePayload>> */
    private array $registry = [];

    /** @param class-string<DeserializablePayload> $payloadClass */
    public function register(string $type, string $payloadClass): self
    {
        if (trim($type) === '' || ! is_subclass_of($payloadClass, DeserializablePayload::class)) {
            throw new InvalidArgumentException('Payload registration is invalid.');
        }

        if (isset($this->registry[$type]) && $this->registry[$type] !== $payloadClass) {
            throw new InvalidArgumentException("Payload type {$type} is already registered.");
        }

        $this->registry[$type] = $payloadClass;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deserialize(string $type, array $data): LedgerPayload
    {
        $class = $this->registry[$type] ?? null;

        return $class === null
            ? GenericPayload::make($type, $data)
            : $class::fromPayload($data);
    }
}
