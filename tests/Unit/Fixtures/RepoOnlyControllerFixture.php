<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

final class RepoOnlyControllerFixture
{
    public function __construct(
        public OpenApiTestsFakeGroupRepository $groupRepository,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function indexAction(): array
    {
        return $this->groupRepository->all();
    }
}
