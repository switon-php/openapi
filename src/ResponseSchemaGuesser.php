<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use ReflectionMethod;
use Switon\Core\Attribute\Autowired;

/**
 * Default {@see ResponseSchemaGuesserInterface}.
 *
 * Strict strategy: use native return-type inference only.
 */
class ResponseSchemaGuesser implements ResponseSchemaGuesserInterface
{
    #[Autowired] protected PhpReturnSchemaInterface $phpReturnSchema;

    public function guess(ReflectionMethod $method): ?array
    {
        return $this->phpReturnSchema->inferSchemaFromReturnType($method);
    }
}
