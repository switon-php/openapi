<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use ReflectionMethod;

/**
 * Central strategy entrypoint for optional response schema inference from controller actions.
 *
 * Guidance: Keep inference conservative (prefer null over wrong shape). Route scaffolding can emit a minimal 200 response without body when this returns null.
 *
 * @see \Switon\OpenApi\ResponseSchemaGuesser
 * @see \Switon\OpenApi\RouteCollector
 */
interface ResponseSchemaGuesserInterface
{
    /**
     * Return inferred response schema only when it is considered deterministic.
     *
     * @return array<string, mixed>|null null means "guess failed"
     */
    public function guess(ReflectionMethod $method): ?array;
}
