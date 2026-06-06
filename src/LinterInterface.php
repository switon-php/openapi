<?php

declare(strict_types=1);

namespace Switon\OpenApi;

/**
 * Validates merged OpenAPI document quality rules for CI and local checks.
 *
 * Guidance: use this after YAML fragments are merged so CI and local tooling can fail fast on spec quality problems.
 *
 * @see \Switon\OpenApi\Linter
 */
interface LinterInterface
{
    /**
     * @param string $root Absolute OpenAPI root directory
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function lint(string $root): array;
}
