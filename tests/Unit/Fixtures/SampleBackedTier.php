<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

enum SampleBackedTier: int
{
    case Free = 0;
    case Pro = 1;
}
