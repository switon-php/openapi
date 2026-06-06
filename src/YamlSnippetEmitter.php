<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use function array_is_list;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function str_contains;
use function str_repeat;
use function str_replace;
use function str_starts_with;

/**
 * Emits block-style YAML for nested arrays (OpenAPI paths/schemas) without flow mappings.
 *
 * @see \Switon\OpenApi\JsonSchemaFromSample
 */
final class YamlSnippetEmitter
{
    /**
     * @param array<string|int, mixed> $value
     */
    public static function dump(array $value, int $baseIndent = 0): string
    {
        return self::emitMapping($value, $baseIndent);
    }

    /**
     * @param array<string|int, mixed> $map
     */
    protected static function emitMapping(array $map, int $level): string
    {
        $pad = str_repeat('  ', $level);
        $lines = [];
        foreach ($map as $k => $v) {
            $key = self::yamlKey((string)$k);
            if (is_array($v)) {
                if ($v === []) {
                    $lines[] = $pad . $key . ': []';
                    continue;
                }
                if (array_is_list($v)) {
                    $lines[] = $pad . $key . ':';
                    $lines[] = self::emitSequence($v, $level + 1);
                    continue;
                }
                $lines[] = $pad . $key . ':';
                $lines[] = self::emitMapping($v, $level + 1);
                continue;
            }
            $lines[] = $pad . $key . ': ' . self::scalarInline($v);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<mixed> $seq
     */
    protected static function emitSequence(array $seq, int $level): string
    {
        $pad = str_repeat('  ', $level);
        $lines = [];
        foreach ($seq as $item) {
            if (is_array($item) && $item !== [] && !array_is_list($item)) {
                $lines[] = $pad . '-';
                $lines[] = self::emitMapping($item, $level + 1);
                continue;
            }
            if (is_array($item) && array_is_list($item)) {
                $lines[] = $pad . '-';
                $lines[] = self::emitSequence($item, $level + 1);
                continue;
            }
            $lines[] = $pad . '- ' . self::scalarInline($item);
        }

        return implode("\n", $lines);
    }

    protected static function yamlKey(string $k): string
    {
        if ($k !== '' && ctype_digit($k)) {
            return "'" . $k . "'";
        }
        if (preg_match('/^[a-zA-Z_]\w*$/', $k) === 1) {
            return $k;
        }

        return "'" . str_replace("'", "''", $k) . "'";
    }

    protected static function scalarInline(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        if (!is_string($v)) {
            return "''";
        }
        if (
            $v === ''
            || str_starts_with($v, ' ')
            || str_starts_with($v, '-')
            || str_contains($v, "\n")
            || str_contains($v, ': ')
            || str_contains($v, '#')
            || preg_match('/[:@`[\]{},&*!|>%"\'\\\\]/', $v) === 1
        ) {
            return "'" . str_replace("'", "''", $v) . "'";
        }

        return $v;
    }
}
