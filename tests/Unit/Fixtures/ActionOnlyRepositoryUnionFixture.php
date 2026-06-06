<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

final class ActionOnlyRepositoryUnionFixture
{
    public OpenApiTestsFakeGroupRepository|ActionOnlyRepositoryContractRepository|null $groupRepository;

    public function indexAction(): array
    {
        return $this->groupRepository->all();
    }
}
