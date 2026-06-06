<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Switon\OpenApi\PhpReturnSchema;
use Switon\OpenApi\Tests\Unit\Fixtures\PhpReturnSchemaChildFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\PhpReturnSchemaRootFixture;

final class PhpReturnSchemaCoverageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Fixtures/PhpReturnSchemaFixtures.php';
    }

    public function testInfersBuiltinReturnTypes(): void
    {
        $infer = new PhpReturnSchema();
        $this->assertSame(['type' => 'integer'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'intAction')));
        $this->assertSame(['type' => 'array'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'arrayAction')));
        $this->assertSame(['type' => 'array'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'iterableAction')));
        $this->assertSame(['type' => 'object'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'objectAction')));
        $this->assertSame(['type' => 'string'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'stringAction')));
        $this->assertSame(['type' => 'boolean'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'trueAction')));
        $this->assertNull($infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'falseAction')));
        $this->assertNull($infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'mixedAction')));
        $this->assertNull($infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'voidAction')));
        $this->assertNull($infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'neverAction')));
    }

    public function testInfersNamedReturnTypesAndSkipsNonSchemaableTypes(): void
    {
        $infer = new PhpReturnSchema();

        $this->assertSame(['type' => 'string', 'format' => 'date-time'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'dateTimeAction')));
        $this->assertSame(['type' => 'string', 'enum' => ['Alpha', 'Beta']], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'enumAction')));
        $this->assertSame(['type' => 'integer'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'backedIntEnumAction')));
        $this->assertSame(['type' => 'string'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'backedStringEnumAction')));
        $this->assertNull($infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'interfaceAction')));
        $this->assertNull($infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'throwableAction')));
        $this->assertNull($infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'generatorAction')));
        $this->assertSame(['type' => 'object'], $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'unknownClassAction')));
        $this->assertNull($infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'intersectionAction')));
    }

    public function testInfersObjectSchemasForSelfStaticParentCircularAndDepthLimits(): void
    {
        $infer = new PhpReturnSchema();

        $selfSchema = $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'selfAction'));
        $this->assertSame('object', $selfSchema['type'] ?? null);
        $this->assertSame(['type' => 'integer'], $selfSchema['properties']['id'] ?? null);

        $staticSchema = $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'staticAction'));
        $this->assertSame('object', $staticSchema['type'] ?? null);

        $parentSchema = $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaChildFixture::class, 'parentAction'));
        $this->assertSame('object', $parentSchema['type'] ?? null);
        $this->assertSame(['type' => 'integer'], $parentSchema['properties']['id'] ?? null);

        $recursiveSchema = $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'recursiveAction'));
        $this->assertSame('object', $recursiveSchema['type'] ?? null);
        $this->assertSame('circular reference', $recursiveSchema['properties']['next']['description'] ?? null);

        $depthSchema = $infer->inferSchemaFromReturnType($this->method(PhpReturnSchemaRootFixture::class, 'depthAction'));
        $this->assertSame('object', $depthSchema['type'] ?? null);
        $this->assertSame('max expansion depth', $depthSchema['properties']['next']['properties']['next']['properties']['next']['properties']['next']['properties']['next']['description'] ?? null);
    }

    private function method(string $class, string $method): ReflectionMethod
    {
        return new ReflectionMethod($class, $method);
    }
}
