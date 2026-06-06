<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

final class UntypedPropertyControllerFixture
{
    /** @var mixed */
    public $legacy;

    public function touchAction(): void
    {
        $this->legacy = 1;
    }
}
