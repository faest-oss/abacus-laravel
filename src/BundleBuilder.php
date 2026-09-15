<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\ReversalDraft;
use Faest\Abacus\Data\TransactionDraft;
use InvalidArgumentException;

class BundleBuilder
{
    /** @var array<int, TransactionDraft> */
    private array $drafts = [];

    public function __construct(private readonly Abacus $abacus) {}

    public function post(
        string $ledgerType,
        string $ledgerId,
        LedgerPayload $payload,
        ?int $expectedVersion = null,
    ): self {
        $this->drafts[] = TransactionDraft::make($ledgerType, $ledgerId, $payload)
            ->failIfVersionIsnt($expectedVersion);

        return $this;
    }

    public function addDraft(TransactionDraft $draft): self
    {
        $this->drafts[] = $draft;

        return $this;
    }

    /**
     * @param  array<int, TransactionDraft>  $drafts
     */
    public function addDrafts(array $drafts): self
    {
        foreach ($drafts as $draft) {
            $this->addDraft($draft);
        }

        return $this;
    }

    public function reverse(string $transactionId, ?int $expectedVersion = null): self
    {
        $transactionToReverse = $this->abacus->findTransaction($transactionId);

        if (! $transactionToReverse) {
            throw new InvalidArgumentException("Transaction {$transactionId} not found");
        }

        $ledger = $this->abacus->resolveLedger($transactionToReverse->ledger_type);
        $payload = $ledger->deserialize($transactionToReverse->payload_type, $transactionToReverse->payload);
        $opposing = $ledger->computeOpposing($payload);

        $this->drafts[] = TransactionDraft::make(
            $transactionToReverse->ledger_type,
            $transactionToReverse->ledger_id,
            $opposing,
        )
            ->failIfVersionIsnt($expectedVersion)
            ->reverses($transactionToReverse->id);

        return $this;
    }

    public function postReversal(ReversalDraft $draft): self
    {
        return $this->reverse($draft->transactionId, $draft->expectedVersion);
    }

    /**
     * @return array<int, TransactionDraft>
     */
    public function getDrafts(): array
    {
        return $this->drafts;
    }
}
