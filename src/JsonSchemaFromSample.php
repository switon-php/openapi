<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use JsonException;

use function array_is_list;
use function array_unique;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function preg_match;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Default {@see JsonSchemaFromSampleInterface}.
 *
 * @see \Switon\OpenApi\YamlSnippetEmitter
 */
class JsonSchemaFromSample implements JsonSchemaFromSampleInterface
{
    public function inferSchema(mixed $sample): array
    {
        return $this->inferValue($sample);
    }

    /**
     * @return array<string, mixed>
     */
    protected function inferValue(mixed $value): array
    {
        if ($value === null) {
            return ['nullable' => true];
        }
        if (is_bool($value)) {
            return ['type' => 'boolean'];
        }
        if (is_int($value)) {
            return ['type' => 'integer'];
        }
        if (is_float($value)) {
            return ['type' => 'number'];
        }
        if (is_string($value)) {
            return $this->inferStringSchema($value);
        }
        if (!is_array($value)) {
            return ['type' => 'string', 'description' => 'unsupported PHP type in sample'];
        }
        if ($value === []) {
            return ['type' => 'array'];
        }
        if (array_is_list($value)) {
            return $this->inferListSchema($value);
        }

        /** @var array<string, mixed> $value */
        $properties = [];
        foreach ($value as $name => $child) {
            if (!is_string($name) && !is_int($name)) {
                continue;
            }
            $properties[(string)$name] = $this->inferValue($child);
        }

        $out = ['type' => 'object'];
        if ($properties !== []) {
            $out['properties'] = $properties;
        }

        return $out;
    }

    /**
     * @param list<mixed> $value
     *
     * @return array<string, mixed>
     */
    protected function inferListSchema(array $value): array
    {
        $itemSchemas = [];
        foreach ($value as $el) {
            $itemSchemas[] = $this->inferValue($el);
        }

        try {
            $encoded = [];
            foreach ($itemSchemas as $s) {
                $this->ksortRecursive($s);
                $encoded[] = json_encode($s, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
        } catch (JsonException) {
            return [
                'type' => 'array',
                'items' => $itemSchemas[0],
            ];
        }

        $uniqueEnc = array_unique($encoded);
        if (count($uniqueEnc) === 1) {
            return [
                'type' => 'array',
                'items' => $itemSchemas[0],
            ];
        }

        $deduped = [];
        $seen = [];
        foreach ($itemSchemas as $i => $schema) {
            $e = $encoded[$i];
            if (isset($seen[$e])) {
                continue;
            }
            $seen[$e] = true;
            $deduped[] = $schema;
        }

        return [
            'type' => 'array',
            'items' => count($deduped) === 1 ? $deduped[0] : ['oneOf' => $deduped],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function inferStringSchema(string $value): array
    {
        $out = ['type' => 'string'];
        $format = $this->guessStringFormat($value);
        if ($format !== null) {
            $out['format'] = $format;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function ksortRecursive(array &$node): void
    {
        ksort($node);
        foreach ($node as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }

    protected function guessStringFormat(string $s): ?string
    {
        if ($s === '') {
            return null;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $s) === 1) {
            return 'uuid';
        }
        // `date-time` must include a time component; otherwise a pure date is classified as `date` below.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/', $s) === 1) {
            return 'date-time';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
            return 'date';
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $s) === 1) {
            return 'uri';
        }

        return null;
    }
}
