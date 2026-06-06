<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

final class OnlyMethodCallControllerFixture
{
    public function saveAction(): void
    {
        $this->persist();
    }

    protected function persist(): void
    {
    }
}
