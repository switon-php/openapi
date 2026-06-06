<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

final class InvokesThisMethodWithRepoFixture
{
    public function __construct(
        public OpenApiTestsFakeGroupRepository $groupRepository,
    ) {
    }

    public function indexAction(): array
    {
        $this->touch();

        return $this->groupRepository->all();
    }

    protected function touch(): void
    {
    }
}
