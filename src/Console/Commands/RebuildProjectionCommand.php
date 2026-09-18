<?php

declare(strict_types=1);

namespace Faest\Abacus\Console\Commands;

use Faest\Abacus\Abacus;
use Faest\Abacus\Contracts\ReplayableProjector;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;

final class RebuildProjectionCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'abacus:projection:rebuild
        {projector : Replayable projector class}
        {--ledger= : Ledger type or ledger class}
        {--chunk=1000 : Number of transactions loaded per page}
        {--force : Run without confirmation in production}';

    protected $description = 'Reset and rebuild one replayable Abacus projection';

    public function handle(Abacus $abacus): int
    {
        $projector = $this->argument('projector');
        $ledgerType = $this->option('ledger');
        $chunkOption = $this->option('chunk');

        if (! is_string($projector)
            || ! is_subclass_of($projector, ReplayableProjector::class)) {
            throw new InvalidArgumentException('The projector must implement ReplayableProjector.');
        }

        if (! is_string($ledgerType) || trim($ledgerType) === '') {
            throw new InvalidArgumentException('The --ledger option is required.');
        }

        if (! is_string($chunkOption)
            || filter_var($chunkOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('The --chunk option must be a positive integer.');
        }

        if (! $this->confirmToProceed('The existing projection will be reset before replay.')) {
            return self::FAILURE;
        }

        $processed = $abacus->rebuildProjection($projector, $ledgerType, (int) $chunkOption);

        $this->components->info("Projection rebuilt successfully. Processed {$processed} transactions.");

        return self::SUCCESS;
    }
}
