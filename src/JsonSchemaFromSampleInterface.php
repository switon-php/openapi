<?php

declare(strict_types=1);

namespace Switon\OpenApi;

/**
 * Builds an OpenAPI 3.0–style JSON Schema object from one decoded JSON value (e.g. a response body sample).
 *
 * Guidance: Refine <code>type</code> and <code>items</code> after generation; list samples infer <code>items</code> from all elements (same shape → one schema; mixed → <code>oneOf</code>). Strings may get <code>format</code> heuristics (uuid, date-time, date, uri).
 *
 * @see \Switon\OpenApi\JsonSchemaFromSample
 */
interface JsonSchemaFromSampleInterface
{
    /**
     * @return array<string, mixed>
     */
    public function inferSchema(mixed $sample): array;
}
