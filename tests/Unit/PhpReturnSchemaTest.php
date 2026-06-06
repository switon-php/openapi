<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Switon\OpenApi\PhpReturnSchema;
use Switon\OpenApi\Tests\Unit\Fixtures\ReturnTypeSamples;

final class PhpReturnSchemaTest extends TestCase
{
    public function testInfersObjectFromPublicProperties(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'editAction');
        $schema = $infer->inferSchemaFromReturnType($m);
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertIsArray($schema['properties'] ?? null);
        $this->assertSame(['type' => 'integer'], $schema['properties']['id'] ?? null);
        $this->assertSame(['type' => 'string'], $schema['properties']['name'] ?? null);
    }

    public function testVoidReturnsNull(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'voidAction');
        $this->assertNull($infer->inferSchemaFromReturnType($m));
    }

    public function testHttpResponseInterfaceReturnsNull(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'responseAction');
        $this->assertNull($infer->inferSchemaFromReturnType($m));
    }

    public function testNullableUnionUsesObjectSchema(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'nullableAction');
        $schema = $infer->inferSchemaFromReturnType($m);
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
    }

    public function testBackedEnumReturnsScalarType(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'backedEnumAction');
        $schema = $infer->inferSchemaFromReturnType($m);
        $this->assertSame(['type' => 'integer'], $schema);
    }

    public function testIntersectionReturnTypeReturnsNull(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'intersectAction');
        $this->assertNull($infer->inferSchemaFromReturnType($m));
    }

    public function testIntScalarReturn(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'intScalarAction');
        $this->assertSame(['type' => 'integer'], $infer->inferSchemaFromReturnType($m));
    }

    public function testArrayBuiltinReturn(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'arrayBuiltinAction');
        $this->assertSame(['type' => 'array'], $infer->inferSchemaFromReturnType($m));
    }

    public function testSelfReturnUsesDeclaringClass(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'selfAction');
        $schema = $infer->inferSchemaFromReturnType($m);
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
    }

    public function testIntOrStringUnionUsesFirstConcreteSchema(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'intOrStringAction');
        $schema = $infer->inferSchemaFromReturnType($m);
        $this->assertIsArray($schema);
        $this->assertContains($schema['type'] ?? null, ['integer', 'string']);
    }

    public function testUnitEnumReturnListsCases(): void
    {
        $infer = new PhpReturnSchema();
        $m = new ReflectionMethod(ReturnTypeSamples::class, 'unitEnumAction');
        $schema = $infer->inferSchemaFromReturnType($m);
        $this->assertIsArray($schema);
        $this->assertSame('string', $schema['type'] ?? null);
        $this->assertSame(['Alpha', 'Beta'], $schema['enum'] ?? null);
    }
}
