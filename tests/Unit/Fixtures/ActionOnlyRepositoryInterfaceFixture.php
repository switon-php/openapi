<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

final class ActionOnlyRepositoryInterfaceFixture
{
    public ActionOnlyRepositoryContractRepository $groupRepository;

    public function indexAction(): array
    {
        return $this->groupRepository->all();
    }
}
