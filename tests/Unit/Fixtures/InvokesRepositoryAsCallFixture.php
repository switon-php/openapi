<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

final class InvokesRepositoryAsCallFixture
{
    public function __construct(
        public OpenApiTestsFakeGroupRepository $groupRepository,
    ) {
    }

    public function indexAction(): array
    {
        return $this->groupRepository();
    }
}
