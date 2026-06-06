<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

use Countable;

final class ActionOnlyRepositoryIntersectionFixture
{
    public OpenApiTestsFakeGroupRepository&Countable $groupRepository;

    public function indexAction(): array
    {
        return $this->groupRepository->all();
    }
}
