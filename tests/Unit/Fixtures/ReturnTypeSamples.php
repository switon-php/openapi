<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

use Switon\Http\ResponseInterface;
use BadMethodCallException;
use Countable;
use Iterator;

/**
 * Fixtures for {@see \Switon\OpenApi\Tests\Unit\PhpReturnSchemaTest} (reflection only; bodies unused).
 */
final class ReturnTypeSamples
{
    public function editAction(): SampleGroup
    {
        throw new BadMethodCallException('fixture');
    }

    public function voidAction(): void
    {
    }

    public function responseAction(): ResponseInterface
    {
        throw new BadMethodCallException('fixture');
    }

    public function nullableAction(): ?SampleGroup
    {
        throw new BadMethodCallException('fixture');
    }

    public function backedEnumAction(): SampleBackedTier
    {
        throw new BadMethodCallException('fixture');
    }

    public function intersectAction(): Iterator&Countable
    {
        throw new BadMethodCallException('fixture');
    }

    public function intScalarAction(): int
    {
        throw new BadMethodCallException('fixture');
    }

    public function arrayBuiltinAction(): array
    {
        throw new BadMethodCallException('fixture');
    }

    public function selfAction(): self
    {
        throw new BadMethodCallException('fixture');
    }

    public function intOrStringAction(): int|string
    {
        throw new BadMethodCallException('fixture');
    }

    public function unitEnumAction(): SampleUnitEnum
    {
        throw new BadMethodCallException('fixture');
    }
}
