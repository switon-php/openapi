<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use ReflectionMethod;

/**
 * Builds a minimal OpenAPI JSON Schema object from a controller action’s native return type (public properties on DTO-like classes).
 *
 * Guidance: Stubs only; refine <code>format</code>, <code>$ref</code>, and unions in YAML. Skip when the type is void, HTTP response, or non-object JSON root.
 *
 * @see \Switon\OpenApi\PhpReturnSchema
 * @see \Switon\OpenApi\RouteCollector
 */
interface PhpReturnSchemaInterface
{
    /**
     * @return array<string, mixed>|null null when no JSON body stub should be emitted
     */
    public function inferSchemaFromReturnType(ReflectionMethod $method): ?array;
}
