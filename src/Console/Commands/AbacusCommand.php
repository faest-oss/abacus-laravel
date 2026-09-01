<?php

declare(strict_types=1);

namespace Faest\Abacus\Console\Commands;

use Illuminate\Console\Command;

class AbacusCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'abacus:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package abacus.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Abacus placeholder command executed.');

        return self::SUCCESS;
    }
}
