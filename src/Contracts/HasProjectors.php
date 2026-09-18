<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

interface HasProjectors
{
    /**
     * @return list<class-string<Projector|OperationProjector>|Projector|OperationProjector>
     */
    public function projectors(): array;
}
