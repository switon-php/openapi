<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\OpenApi\JsonSchemaFromSample;
use stdClass;

final class JsonSchemaFromSampleTest extends TestCase
{
    public function testObjectWithProperties(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema(['id' => 1, 'name' => 'a']);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('id', $schema['properties']);
    }

    public function testListHomogeneousItems(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema([1, 2, 3]);
        $this->assertSame('array', $schema['type']);
        $this->assertSame(['type' => 'integer'], $schema['items']);
    }

    public function testListMixedUsesOneOf(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema([1, 'a']);
        $this->assertSame('array', $schema['type']);
        $this->assertArrayHasKey('oneOf', $schema['items']);
        $this->assertCount(2, $schema['items']['oneOf']);
    }

    public function testUuidFormat(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema('550e8400-e29b-41d4-a716-446655440000');
        $this->assertSame('string', $schema['type']);
        $this->assertSame('uuid', $schema['format']);
    }

    public function testDateTimeFormat(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema('2026-04-05T12:00:00Z');
        $this->assertSame('string', $schema['type']);
        $this->assertSame('date-time', $schema['format']);
    }

    public function testDateFormat(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema('2026-04-05');
        $this->assertSame('string', $schema['type']);
        $this->assertSame('date', $schema['format']);
    }

    public function testDateTimeWithSpaceSeparatorFormat(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema('2026-04-05 12:00:00+08:00');
        $this->assertSame('string', $schema['type']);
        $this->assertSame('date-time', $schema['format']);
    }

    public function testDateWithoutTimeIsNotDateTime(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema('2026-04-05');
        $this->assertNotSame('date-time', $schema['format']);
    }

    public function testNullSample(): void
    {
        $s = new JsonSchemaFromSample();
        $this->assertSame(['nullable' => true], $s->inferSchema(null));
    }

    public function testBooleanAndFloatScalars(): void
    {
        $s = new JsonSchemaFromSample();
        $this->assertSame(['type' => 'boolean'], $s->inferSchema(true));
        $this->assertSame(['type' => 'number'], $s->inferSchema(1.5));
    }

    public function testEmptyArraySchema(): void
    {
        $s = new JsonSchemaFromSample();
        $this->assertSame(['type' => 'array'], $s->inferSchema([]));
    }

    public function testUnsupportedPhpTypeFallsBackToStringDescription(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema(new stdClass());
        $this->assertSame('string', $schema['type']);
        $this->assertSame('unsupported PHP type in sample', $schema['description']);
    }

    public function testUriFormatDetection(): void
    {
        $s = new JsonSchemaFromSample();
        $schema = $s->inferSchema('https://example.com/path');
        $this->assertSame('string', $schema['type']);
        $this->assertSame('uri', $schema['format']);
    }
}
