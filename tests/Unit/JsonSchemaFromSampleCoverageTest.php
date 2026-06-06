<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\OpenApi\JsonSchemaFromSample;
use stdClass;

final class JsonSchemaFromSampleCoverageTest extends TestCase
{
    public function testInfersNullArrayObjectAndUriSamples(): void
    {
        $infer = new JsonSchemaFromSample();

        $this->assertSame(['nullable' => true], $infer->inferSchema(null));
        $this->assertSame(['type' => 'array'], $infer->inferSchema([]));
        $this->assertSame(['type' => 'string', 'description' => 'unsupported PHP type in sample'], $infer->inferSchema(new stdClass()));
        $this->assertSame(['type' => 'string', 'format' => 'uri'], $infer->inferSchema('https://example.com/docs'));
    }

    public function testFallsBackToFirstItemWhenListEncodingFails(): void
    {
        $infer = new JsonSchemaFromSample();
        $bad = "a" . chr(0xB1);
        $schema = $infer->inferSchema([$bad, 'ok']);

        $this->assertSame('array', $schema['type']);
        $this->assertSame(['type' => 'string'], $schema['items']);
    }
}
