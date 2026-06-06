<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use ReflectionMethod;
use Switon\OpenApi\PhpReturnSchema;
use Switon\OpenApi\ResponseSchemaGuesser;
use Switon\OpenApi\Tests\Unit\Fixtures\RepoOnlyNoReturnControllerFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\ReturnTypeSamples;
use Switon\OpenApi\Tests\TestCase;

final class ResponseSchemaGuesserTest extends TestCase
{
    public function testGuessUsesReturnTypeInferenceFirst(): void
    {
        $guesser = $this->makeGuesser();
        $method = new ReflectionMethod(ReturnTypeSamples::class, 'editAction');

        $schema = $guesser->guess($method);

        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertArrayHasKey('properties', $schema);
    }

    public function testGuessReturnsNullWhenReturnTypeUnknown(): void
    {
        $guesser = $this->makeGuesser();
        $method = new ReflectionMethod(RepoOnlyNoReturnControllerFixture::class, 'indexAction');
        $this->assertNull($guesser->guess($method));
    }

    private function makeGuesser(): ResponseSchemaGuesser
    {
        return $this->make(ResponseSchemaGuesser::class, [
            'phpReturnSchema' => new PhpReturnSchema(),
        ]);
    }
}
