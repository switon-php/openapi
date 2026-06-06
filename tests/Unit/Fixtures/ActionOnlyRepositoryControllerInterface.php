<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

interface ActionOnlyRepositoryControllerInterface
{
    public function indexAction(): array;
}
