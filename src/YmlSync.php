<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Yaml\YamlReaderInterface;

use function array_merge;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function ksort;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_contains;
use function str_replace;
use function strcmp;
use function strlen;
use function strtolower;
use function strtr;
use function trim;

/**
 * Default {@see YmlSyncInterface}.
 *
 * Appends missing path/operation stubs by text when the file layout is recognized; otherwise warns and skips. Also auto-creates missing <code>components/entity.{handler-id}.yml</code> files (existing files are left untouched).
 * Path templates <code>{name}</code> get per-operation <code>parameters</code> stubs (<code>in: path</code>, <code>required: true</code>, <code>schema.type: string</code>) for every HTTP method on that path.
 * When the route row includes <code>responseSchema</code> (from {@see RouteCollector} / {@see ResponseSchemaGuesserInterface}), <code>200.content.application/json.schema</code> is emitted.
 *
 * @see \Switon\OpenApi\RouteCollectorInterface
 */
class YmlSync implements YmlSyncInterface
{
    #[Autowired] protected RouteCollectorInterface $routeCollector;

    #[Autowired] protected FilesystemInterface $filesystem;

    #[Autowired] protected YamlReaderInterface $yamlReader;

    public function sync(string $root, string $controller, string $action, bool $tail = false): array
    {
        $dir = rtrim($root, '/');
        $controllers = $dir . '/controllers';
        if (!$this->filesystem->exists($controllers)) {
            $this->filesystem->mkdir($controllers);
        }
        $components = $dir . '/components';
        if (!$this->filesystem->exists($components)) {
            $this->filesystem->mkdir($components);
        }

        $byController = [];
        foreach ($this->routeCollector->collect() as $row) {
            if ($row['verb'] === '*') {
                continue;
            }
            [$fqcn, $methodName] = explode('::', $row['handler'], 2);
            if ($controller !== '' && $fqcn !== $controller) {
                continue;
            }
            if ($action !== '' && $methodName !== $action) {
                continue;
            }
            if (!isset($byController[$fqcn])) {
                $byController[$fqcn] = [];
            }
            $byController[$fqcn][] = $row;
        }
        ksort($byController);

        $written = [];
        $warnings = [];
        foreach ($byController as $fqcn => $routes) {
            $controllerId = $this->handlerIdPrefixFromRoutes($routes);
            if ($controllerId !== '') {
                $entityPath = $this->entitySchemaFilePath($components, $controllerId);
                if (!$this->filesystem->exists($entityPath)) {
                    $this->filesystem->write($entityPath, $this->buildEntitySchemaYml('entity.' . $controllerId));
                    $written[] = $entityPath;
                }
            }

            $path = $controllers . '/' . $this->controllerYamlFileName($routes);
            if (!$this->filesystem->exists($path)) {
                $this->filesystem->write($path, $this->buildControllerYml($routes));
                $written[] = $path;
                continue;
            }

            $raw = $this->filesystem->read($path);
            if (str_contains($raw, "\t")) {
                $warnings[] = 'open-api:sync: skipped ' . $path . ': tabs in file (Switon YAML subset disallows tabs)';

                continue;
            }

            /** @var array<string, mixed> $doc */
            $doc = $this->yamlReader->parse($raw);

            $existingPaths = isset($doc['paths']) && is_array($doc['paths']) ? $doc['paths'] : [];
            $missing = $this->listMissingOperations($routes, $existingPaths);
            if ($missing === []) {
                continue;
            }

            $result = $this->tryAppendStubsByText($raw, $missing, $tail);
            if ($result['content'] === null) {
                $warnings[] = 'open-api:sync: skipped ' . $path . ': ' . ($result['error'] ?? 'could not append safely');

                continue;
            }

            $this->filesystem->write($path, $result['content']);
            $written[] = $path;
        }

        return ['written' => $written, 'warnings' => $warnings];
    }

    /**
     * @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}> $routes
     * @param array<string, mixed> $existingPaths
     *
     * @return list<array{path: string, route: array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}}>
     */
    protected function listMissingOperations(array $routes, array $existingPaths): array
    {
        $out = [];
        foreach ($routes as $r) {
            $methodKey = $this->openApiMethodKey($r['verb']);
            if ($methodKey === null) {
                continue;
            }
            $pathPattern = $r['pattern'];
            $ops = $existingPaths[$pathPattern] ?? null;
            if (is_array($ops) && isset($ops[$methodKey])) {
                continue;
            }
            $out[] = ['path' => $pathPattern, 'route' => $r];
        }

        return $out;
    }

    /**
     * @param list<array{path: string, route: array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}}> $missing
     *
     * @return array{content: ?string, error: ?string}
     */
    protected function tryAppendStubsByText(string $raw, array $missing, bool $tail): array
    {
        if (str_contains($raw, "\t")) {
            return ['content' => null, 'error' => 'tabs in file (Switon YAML subset disallows tabs)'];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $pathsLineIdx = $this->findPathsLineIndex($lines);
        if ($pathsLineIdx === null) {
            return ['content' => null, 'error' => 'no top-level paths: key not found'];
        }

        $byPath = [];
        foreach ($missing as $item) {
            $p = $item['path'];
            if (!isset($byPath[$p])) {
                $byPath[$p] = [];
            }
            $byPath[$p][] = $item['route'];
        }
        ksort($byPath, SORT_STRING);

        /** @var list<array{insertAt: int, chunk: list<string>}> $jobs */
        $jobs = [];
        /** @var list<array{insertAt: int, pathPattern: string, chunk: list<string>}> $newPathInsertJobs */
        $newPathInsertJobs = [];
        foreach ($byPath as $pathPattern => $pathRoutes) {
            usort($pathRoutes, function (array $a, array $b): int {
                $ka = $this->openApiMethodKey($a['verb']) ?? '';
                $kb = $this->openApiMethodKey($b['verb']) ?? '';

                return strcmp($ka, $kb);
            });
            $pathLineIdx = $this->findPathLineIndex($lines, $pathsLineIdx, $pathPattern);
            if ($pathLineIdx !== null) {
                $boundary = $this->findNextPathOrTopLevelBoundary($lines, $pathLineIdx);
                if ($boundary === null) {
                    return ['content' => null, 'error' => 'could not find boundary after path ' . $pathPattern];
                }
                $chunk = [];
                foreach ($pathRoutes as $r) {
                    $chunk = array_merge($chunk, $this->buildMethodStubLines($r));
                }
                $jobs[] = ['insertAt' => $boundary, 'chunk' => $chunk];
            } else {
                $insertAt = $tail
                    ? $this->findFirstTopLevelKeyAfterPaths($lines, $pathsLineIdx)
                    : $this->findInsertIndexForNewPath($lines, $pathsLineIdx, $pathPattern);
                $newPathInsertJobs[] = [
                    'insertAt' => $insertAt,
                    'pathPattern' => $pathPattern,
                    'chunk' => $this->buildPathBlockLines($pathPattern, $pathRoutes),
                ];
            }
        }
        if ($newPathInsertJobs !== []) {
            /** @var array<int, list<array{pathPattern: string, chunk: list<string>}>> $groupedByLine */
            $groupedByLine = [];
            foreach ($newPathInsertJobs as $nj) {
                $at = $nj['insertAt'];
                if (!isset($groupedByLine[$at])) {
                    $groupedByLine[$at] = [];
                }
                $groupedByLine[$at][] = [
                    'pathPattern' => $nj['pathPattern'],
                    'chunk' => $nj['chunk'],
                ];
            }
            foreach ($groupedByLine as $at => $group) {
                usort($group, static fn (array $a, array $b): int => strcmp($a['pathPattern'], $b['pathPattern']));
                $combined = [];
                foreach ($group as $g) {
                    $combined = array_merge($combined, $g['chunk']);
                }
                $jobs[] = ['insertAt' => $at, 'chunk' => $combined];
            }
        }

        usort($jobs, static fn (array $a, array $b): int => $b['insertAt'] <=> $a['insertAt']);
        foreach ($jobs as $job) {
            array_splice($lines, $job['insertAt'], 0, $job['chunk']);
        }

        return ['content' => implode("\n", $lines) . "\n", 'error' => null];
    }

    /**
     * @param list<string> $lines
     */
    protected function findPathsLineIndex(array $lines): ?int
    {
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#') {
                continue;
            }
            if ($this->leadingSpacesLen($line) !== 0) {
                continue;
            }
            if (preg_match('/^paths:\s*(?:#.*)?$/', $t) === 1) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     */
    protected function findPathLineIndex(array $lines, int $pathsLineIdx, string $pathPattern): ?int
    {
        $n = count($lines);
        for ($i = $pathsLineIdx + 1; $i < $n; $i++) {
            $line = $lines[$i];
            if (trim($line) === '' || (isset($line[0]) && $line[0] === '#')) {
                continue;
            }
            $len = $this->leadingSpacesLen($line);
            if ($len === null) {
                return null;
            }
            if ($len === 0 && $this->isTopLevelMappingKeyLine($line)) {
                return null;
            }
            if ($len === 2 && $this->looksLikeOpenApiPathKeyLine($line)) {
                $pk = $this->pathKeyFromIndent2Line($line);
                if ($pk === $pathPattern) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     *
     * @return int|null Insert before this line index (next path or top-level key), or null on ambiguity
     */
    protected function findNextPathOrTopLevelBoundary(array $lines, int $pathLineIdx): ?int
    {
        $n = count($lines);
        for ($j = $pathLineIdx + 1; $j < $n; $j++) {
            $line = $lines[$j];
            if (trim($line) === '' || (isset($line[0]) && $line[0] === '#')) {
                continue;
            }
            $len = $this->leadingSpacesLen($line);
            if ($len === null) {
                return null;
            }
            if ($len === 0 && $this->isTopLevelMappingKeyLine($line)) {
                return $j;
            }
            if ($len === 2 && $this->looksLikeOpenApiPathKeyLine($line)) {
                return $j;
            }
        }

        return $n;
    }

    /**
     * Insert before the first existing path that sorts after <code>$pathPattern</code> (lexicographic on path strings); if none, before <code>components</code> / EOF — keeps new paths in alphabetical order among siblings.
     *
     * @param list<string> $lines
     */
    protected function findInsertIndexForNewPath(array $lines, int $pathsLineIdx, string $pathPattern): int
    {
        $end = $this->findFirstTopLevelKeyAfterPaths($lines, $pathsLineIdx);
        $entries = $this->collectOpenApiPathEntries($lines, $pathsLineIdx, $end);
        foreach ($entries as $entry) {
            if (strcmp($pathPattern, $entry['path']) < 0) {
                return $entry['line'];
            }
        }

        return $end;
    }

    /**
     * First top-level key at column 0 at or after paths content (e.g. components).
     *
     * @param list<string> $lines
     */
    protected function findFirstTopLevelKeyAfterPaths(array $lines, int $pathsLineIdx): int
    {
        $n = count($lines);
        for ($i = $pathsLineIdx + 1; $i < $n; $i++) {
            $line = $lines[$i];
            if (trim($line) === '' || (isset($line[0]) && $line[0] === '#')) {
                continue;
            }
            $len = $this->leadingSpacesLen($line);
            if ($len === null) {
                return $n;
            }
            if ($len === 0 && $this->isTopLevelMappingKeyLine($line)) {
                return $i;
            }
        }

        return $n;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<array{line: int, path: string}>
     */
    protected function collectOpenApiPathEntries(array $lines, int $pathsLineIdx, int $endExclusive): array
    {
        $out = [];
        for ($i = $pathsLineIdx + 1; $i < $endExclusive; $i++) {
            $line = $lines[$i];
            if ($this->leadingSpacesLen($line) === 2 && $this->looksLikeOpenApiPathKeyLine($line)) {
                $pk = $this->pathKeyFromIndent2Line($line);
                if ($pk !== null) {
                    $out[] = ['line' => $i, 'path' => $pk];
                }
            }
        }

        return $out;
    }

    protected function isTopLevelMappingKeyLine(string $line): bool
    {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') {
            return false;
        }

        return preg_match('/^[a-zA-Z_]\w*:\s*(?:#.*)?$/', $t) === 1;
    }

    /**
     * Indent-2 line whose key is an OpenAPI path (not get/post/…).
     */
    protected function looksLikeOpenApiPathKeyLine(string $line): bool
    {
        $pk = $this->pathKeyFromIndent2Line($line);
        if ($pk === null) {
            return false;
        }
        $m = strtolower($pk);

        return !in_array($m, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'], true);
    }

    protected function pathKeyFromIndent2Line(string $line): ?string
    {
        if (preg_match('/^  (.+):\s*(?:#.*)?$/', $line, $m) !== 1) {
            return null;
        }

        return $this->unquoteYamlScalarKey(trim($m[1]));
    }

    protected function unquoteYamlScalarKey(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $first = $raw[0];
        $last = $raw[strlen($raw) - 1];
        if (($first === "'" || $first === '"') && $last === $first && strlen($raw) >= 2) {
            $inner = substr($raw, 1, -1);
            if ($first === "'") {
                return strtr($inner, ["''" => "'"]);
            }

            return $inner;
        }

        return $raw;
    }

    protected function leadingSpacesLen(string $line): ?int
    {
        if (str_contains($line, "\t")) {
            return null;
        }
        if (preg_match('/^ */', $line, $m) !== 1) {
            return 0;
        }

        return strlen($m[0]);
    }

    /**
     * @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}> $routes
     *
     * @return list<string>
     */
    protected function buildPathBlockLines(string $pathPattern, array $routes): array
    {
        $lines = ['  ' . $this->yamlPathKey($pathPattern) . ':'];
        foreach ($routes as $r) {
            $lines = array_merge($lines, $this->buildMethodStubLines($r));
        }

        return $lines;
    }

    /**
     * @param array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>} $r
     *
     * @return list<string>
     */
    protected function buildMethodStubLines(array $r): array
    {
        $http = $this->openApiMethodKey($r['verb']);
        if ($http === null) {
            return [];
        }

        $lines = [
            '    ' . $http . ':',
            '      operationId: ' . $this->yamlSingleQuoted($r['operationId']),
            '      summary: ' . $this->yamlSingleQuoted($r['handler']),
        ];
        $lines = array_merge(
            $lines,
            $this->buildPathParameterStubLines($this->pathParameterNamesFromPattern($r['pattern'])),
        );

        /** @var array<string, mixed>|null $responseSchema */
        $responseSchema = $r['responseSchema'] ?? null;
        $entitySchemaRef = $this->entitySchemaRefFromOperationId($r['operationId']);

        return array_merge($lines, $this->buildResponseStubLines($responseSchema, $entitySchemaRef));
    }

    /**
     * @param array<string, mixed>|null $responseSchema
     * @param string|null $entitySchemaRef Recommended entity schema ref (e.g. <code>#/components/schemas/entity.user</code>)
     *
     * @return list<string>
     */
    protected function buildResponseStubLines(?array $responseSchema, ?string $entitySchemaRef = null): array
    {
        $sharedSimpleRef = '#/components/schemas/response.simple';
        $sharedPageRef = '#/components/schemas/response.page';

        if ($responseSchema === null) {
            $lines = [
                '      responses:',
                "        '200':",
                '          description: OK',
                '          # Recommended shared wrappers (define in openapi.yml):',
                "          # \$ref: '" . $sharedSimpleRef . "'",
                "          # \$ref: '" . $sharedPageRef . "'",
            ];
            if ($entitySchemaRef !== null) {
                $lines[] = '          # Recommended entity model (in openapi/components/entity.*.yml):';
                $lines[] = "          # \$ref: '" . $entitySchemaRef . "'";
            }

            return $lines;
        }

        $dumped = YamlSnippetEmitter::dump($responseSchema, 8);
        $schemaLines = preg_split('/\r\n|\r|\n/', $dumped) ?: [];
        $lines = [
            '      responses:',
            "        '200':",
            '          description: OK',
            '          # Optional: replace inline schema with shared wrappers:',
            "          # \$ref: '" . $sharedSimpleRef . "'",
            "          # \$ref: '" . $sharedPageRef . "'",
        ];
        if ($entitySchemaRef !== null) {
            $lines[] = '          # Optional: use entity model from openapi/components/entity.*.yml:';
            $lines[] = "          # \$ref: '" . $entitySchemaRef . "'";
        }
        $lines = array_merge($lines, [
            '          content:',
            '            application/json:',
            '              schema:',
        ]);
        foreach ($schemaLines as $sl) {
            $t = rtrim($sl, "\r\n");
            if ($t !== '') {
                $lines[] = $t;
            }
        }

        return $lines;
    }

    /**
     * @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}> $routes
     */
    protected function buildControllerYml(array $routes): string
    {
        /** @var array<string, array<string, array{operationId: string, summary: string, responseSchema: array<string, mixed>|null}>> $paths */
        $paths = [];
        foreach ($routes as $r) {
            $methodKey = $this->openApiMethodKey($r['verb']);
            if ($methodKey === null) {
                continue;
            }
            $pathPattern = $r['pattern'];
            if (!isset($paths[$pathPattern])) {
                $paths[$pathPattern] = [];
            }
            $paths[$pathPattern][$methodKey] = [
                'operationId' => $r['operationId'],
                'summary' => $r['handler'],
                'responseSchema' => $r['responseSchema'] ?? null,
            ];
        }
        ksort($paths, SORT_STRING);
        foreach ($paths as $k => $ops) {
            ksort($ops, SORT_STRING);
            $paths[$k] = $ops;
        }

        $lines = [
            '# Generated by open-api:sync. Enrich responses (content/schema) and components; re-run sync appends missing paths/operations only.',
            'paths:',
        ];
        foreach ($paths as $pathKey => $ops) {
            $lines[] = '  ' . $this->yamlPathKey($pathKey) . ':';
            $pathParams = $this->pathParameterNamesFromPattern($pathKey);
            foreach ($ops as $httpMethod => $op) {
                $lines[] = '    ' . $httpMethod . ':';
                $lines[] = '      operationId: ' . $this->yamlSingleQuoted($op['operationId']);
                $lines[] = '      summary: ' . $this->yamlSingleQuoted($op['summary']);
                foreach ($this->buildPathParameterStubLines($pathParams) as $pLine) {
                    $lines[] = $pLine;
                }
                foreach ($this->buildResponseStubLines($op['responseSchema'], $this->entitySchemaRefFromOperationId($op['operationId'])) as $rLine) {
                    $lines[] = $rLine;
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * One YAML per controller; stem = compact {@see \Switon\Core\ClassName::dotId()} — the segment before <code>::</code> in <code>operationId</code> (same as handler id for RBAC).
     *
     * @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}> $routes
     */
    protected function controllerYamlFileName(array $routes): string
    {
        $prefix = $this->handlerIdPrefixFromRoutes($routes);
        if ($prefix !== '') {
            $stem = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $prefix);
            if ($stem !== '' && $stem !== '_') {
                return $stem . '.yml';
            }
        }
        [$fqcn] = explode('::', $routes[0]['handler'], 2);

        return str_replace('\\', '__', $fqcn) . '.yml';
    }

    /**
     * @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string}> $routes
     */
    protected function handlerIdPrefixFromRoutes(array $routes): string
    {
        if ($routes === []) {
            return '';
        }
        $parts = explode('::', $routes[0]['operationId'] ?? '', 2);

        return $parts[0] ?? '';
    }

    /**
     * @param string $operationId Expected handler-id shape: <code>{controllerId}::{action}</code>
     */
    protected function entitySchemaRefFromOperationId(string $operationId): ?string
    {
        $parts = explode('::', $operationId, 2);
        $controllerId = $parts[0] ?? '';
        if ($controllerId === '') {
            return null;
        }

        return '#/components/schemas/entity.' . $controllerId;
    }

    protected function entitySchemaFilePath(string $componentsDir, string $controllerId): string
    {
        $stem = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $controllerId) ?? $controllerId;
        if ($stem === '' || $stem === '_') {
            $stem = 'unknown';
        }

        return $componentsDir . '/entity.' . $stem . '.yml';
    }

    protected function buildEntitySchemaYml(string $schemaName): string
    {
        $lines = [
            '# Generated by open-api:sync. Fill properties as needed.',
            'components:',
            '  schemas:',
            '    ' . $this->yamlSingleQuoted($schemaName) . ':',
            '      type: object',
            '      additionalProperties: true',
        ];

        return implode("\n", $lines) . "\n";
    }

    protected function yamlPathKey(string $path): string
    {
        if ($path !== '' && preg_match('#^[-a-zA-Z0-9_./{}]+$#', $path) === 1) {
            return $path;
        }

        return $this->yamlSingleQuoted($path);
    }

    protected function yamlSingleQuoted(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function openApiMethodKey(string $verb): ?string
    {
        return match (strtoupper($verb)) {
            'GET' => 'get',
            'POST' => 'post',
            'PUT' => 'put',
            'PATCH' => 'patch',
            'DELETE' => 'delete',
            'HEAD' => 'head',
            'OPTIONS' => 'options',
            'TRACE' => 'trace',
            default => null,
        };
    }

    /**
     * Path-template segments <code>{name}</code> (OpenAPI-style); order preserved, duplicates dropped.
     *
     * @return list<string>
     */
    protected function pathParameterNamesFromPattern(string $pattern): array
    {
        if ($pattern === '' || !str_contains($pattern, '{')) {
            return [];
        }
        if (preg_match_all('/\{([^{}]+)\}/', $pattern, $matches) < 1) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($matches[1] as $raw) {
            $name = trim((string)$raw);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = $name;
        }

        return $out;
    }

    /**
     * @param list<string> $paramNames
     *
     * @return list<string>
     */
    protected function buildPathParameterStubLines(array $paramNames): array
    {
        if ($paramNames === []) {
            return [];
        }
        $lines = ['      parameters:'];
        foreach ($paramNames as $name) {
            // Dash-only item + indented map (Switon YamlReader; "- key: val" on one line is not a mapping item).
            $lines[] = '        -';
            $lines[] = '          name: ' . $this->yamlSingleQuoted($name);
            $lines[] = '          in: path';
            $lines[] = '          required: true';
            $lines[] = '          schema:';
            $lines[] = '            type: string';
        }

        return $lines;
    }
}
