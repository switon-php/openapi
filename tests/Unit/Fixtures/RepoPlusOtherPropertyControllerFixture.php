<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

final class RepoPlusOtherPropertyControllerFixture
{
    public function __construct(
        public OpenApiTestsFakeGroupRepository $groupRepository,
        public OpenApiTestsFakeRequestDep      $requestDep,
    ) {
    }

    public function indexAction(): array
    {
        return $this->groupRepository->all($this->requestDep);
    }
}
