<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

use Countable;
use DateTimeImmutable;
use Generator;
use IteratorAggregate;
use RuntimeException;

class PhpReturnSchemaRootFixture
{
    public int $id;

    public string $name;

    public function intAction(): int
    {
        throw new RuntimeException('fixture');
    }

    public function arrayAction(): array
    {
        throw new RuntimeException('fixture');
    }

    public function iterableAction(): iterable
    {
        throw new RuntimeException('fixture');
    }

    public function objectAction(): object
    {
        throw new RuntimeException('fixture');
    }

    public function stringAction(): string
    {
        throw new RuntimeException('fixture');
    }

    public function trueAction(): true
    {
        throw new RuntimeException('fixture');
    }

    public function falseAction(): false
    {
        throw new RuntimeException('fixture');
    }

    public function mixedAction(): mixed
    {
        throw new RuntimeException('fixture');
    }

    public function voidAction(): void
    {
    }

    public function neverAction(): never
    {
        throw new RuntimeException('fixture');
    }

    public function dateTimeAction(): DateTimeImmutable
    {
        throw new RuntimeException('fixture');
    }

    public function enumAction(): PhpReturnSchemaUnitEnum
    {
        throw new RuntimeException('fixture');
    }

    public function backedIntEnumAction(): PhpReturnSchemaBackedIntEnum
    {
        throw new RuntimeException('fixture');
    }

    public function backedStringEnumAction(): PhpReturnSchemaBackedStringEnum
    {
        throw new RuntimeException('fixture');
    }

    public function interfaceAction(): Countable
    {
        throw new RuntimeException('fixture');
    }

    public function throwableAction(): RuntimeException
    {
        throw new RuntimeException('fixture');
    }

    public function generatorAction(): Generator
    {
        throw new RuntimeException('fixture');
    }

    public function unknownClassAction(): PhpReturnSchemaUnknownClass
    {
        throw new RuntimeException('fixture');
    }

    public function selfAction(): self
    {
        throw new RuntimeException('fixture');
    }

    public function staticAction(): static
    {
        throw new RuntimeException('fixture');
    }

    public function intersectionAction(): Countable&IteratorAggregate
    {
        throw new RuntimeException('fixture');
    }

    public function recursiveAction(): PhpReturnSchemaRecursiveNode
    {
        throw new RuntimeException('fixture');
    }

    public function depthAction(): PhpReturnSchemaDepthOne
    {
        throw new RuntimeException('fixture');
    }
}

final class PhpReturnSchemaChildFixture extends PhpReturnSchemaRootFixture
{
    public function parentAction(): parent
    {
        throw new RuntimeException('fixture');
    }
}

final class PhpReturnSchemaRecursiveNode
{
    public ?PhpReturnSchemaRecursiveNode $next = null;
}

final class PhpReturnSchemaDepthOne
{
    public PhpReturnSchemaDepthTwo $next;
}

final class PhpReturnSchemaDepthTwo
{
    public PhpReturnSchemaDepthThree $next;
}

final class PhpReturnSchemaDepthThree
{
    public PhpReturnSchemaDepthFour $next;
}

final class PhpReturnSchemaDepthFour
{
    public PhpReturnSchemaDepthFive $next;
}

final class PhpReturnSchemaDepthFive
{
    public PhpReturnSchemaDepthSix $next;
}

final class PhpReturnSchemaDepthSix
{
    public PhpReturnSchemaDepthSeven $next;
}

final class PhpReturnSchemaDepthSeven
{
}

enum PhpReturnSchemaUnitEnum
{
    case Alpha;
    case Beta;
}

enum PhpReturnSchemaBackedIntEnum: int
{
    case One = 1;
    case Two = 2;
}

enum PhpReturnSchemaBackedStringEnum: string
{
    case One = 'one';
    case Two = 'two';
}

final class PhpReturnSchemaUnknownClass
{
}
