<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

final class ActionOnlyRepositoryUnionWithNonRepositoryFixture
{
    public OpenApiTestsFakeGroupRepository|OpenApiTestsFakeRequestDep|null $groupRepository;

    public function indexAction(): array
    {
        return $this->groupRepository->all();
    }
}
