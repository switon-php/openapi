<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

interface ActionOnlyRepositoryContractRepository
{
    public function all(): array;
}
