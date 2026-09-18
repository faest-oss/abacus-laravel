<?php

declare(strict_types=1);

namespace Faest\Abacus\Models;

use Faest\Abacus\Support\StorageConfiguration;
use Illuminate\Database\Eloquent\Model;

abstract class AbacusModel extends Model
{
    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $storage = app(StorageConfiguration::class);

        $this->setTable($storage->table($this->storageTable()));

        if ($this->getConnectionName() === null) {
            $this->setConnection($storage->connection());
        }
    }

    abstract protected function storageTable(): string;
}
