<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\FilesystemInterface;
use Switon\Yaml\YamlReaderInterface;

use function array_key_exists;
use function array_merge;
use function array_replace_recursive;
use function explode;
use function is_array;
use function is_string;
use function ksort;
use function rtrim;
use function sort;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * Default {@see DocumentBuilderInterface} using {@see YamlReaderInterface}, {@see FilesystemInterface}, and optional local ref dereference.
 *
 * Guidance: use this as the document assembly boundary after fragments exist on disk and before export or lint steps run.
 *
 * @see \Switon\Yaml\YamlReaderInterface
 * @see \Switon\Core\FilesystemInterface
 */
class DocumentBuilder implements DocumentBuilderInterface
{
    protected const int MAX_DEREF_DEPTH = 64;

    #[Autowired] protected YamlReaderInterface $yamlReader;

    #[Autowired] protected FilesystemInterface $filesystem;

    /**
     * Builds one merged OpenAPI document and optionally dereferences local component refs.
     */
    public function build(string $root, bool $deref = false, bool $keepComponents = false): array
    {
        $doc = $this->mergeDocuments($root);
        if (!$deref) {
            return $doc;
        }

        $doc = $this->dereference($doc);
        if (!$keepComponents) {
            unset($doc['components']);
        }

        return $doc;
    }

    /**
     * Merges the main file plus component and controller fragments from the OpenAPI root directory.
     *
     * @return array<string, mixed>
     */
    protected function mergeDocuments(string $root): array
    {
        $root = rtrim($root, '/');
        $doc = $this->defaultSkeleton();

        $main = $this->tryReadMainOpenapi($root);
        if ($main !== null) {
            $doc = $this->applyMainFragment($doc, $main);
        }

        $files = $this->collectFragmentFiles($root);

        foreach ($files as $file) {
            $raw = $this->filesystem->read($file);
            /** @var array<int|string, mixed> $parsed */
            $parsed = $this->yamlReader->parse($raw);
            $fragment = $this->normalizeObjectMap($parsed);
            $doc = $this->mergeFragment($doc, $fragment);
        }

        return $doc;
    }

    /**
     * Collects fragment files in merge order: components first, then controllers, each sorted by file name.
     *
     * @return list<string>
     */
    protected function collectFragmentFiles(string $root): array
    {
        $out = [];
        foreach (['components', 'controllers'] as $dirName) {
            $dir = $root . '/' . $dirName;
            if (!$this->filesystem->exists($dir) || !$this->filesystem->isDir($dir)) {
                continue;
            }
            $files = $this->filesystem->glob($dir . '/*.yml');
            sort($files);
            foreach ($files as $file) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * Returns the fallback OpenAPI skeleton used when fragments omit top-level metadata.
     *
     * @return array<string, mixed>
     */
    protected function defaultSkeleton(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'API',
                'version' => '1.0.0',
            ],
            'paths' => [],
            'components' => [],
        ];
    }

    /**
     * Reads the main <code>openapi.yml</code> fragment when present; legacy <code>.yaml</code> is ignored.
     *
     * @return array<string, mixed>|null
     */
    protected function tryReadMainOpenapi(string $root): ?array
    {
        $path = $root . '/openapi.yml';
        if (!$this->filesystem->exists($path)) {
            return null;
        }

        /** @var array<int|string, mixed> $main */
        $main = $this->yamlReader->parse($this->filesystem->read($path));

        return $this->normalizeObjectMap($main);
    }

    /**
     * Applies the main fragment onto the default skeleton before per-file fragments are merged.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $main
     *
     * @return array<string, mixed>
     */
    protected function applyMainFragment(array $base, array $main): array
    {
        foreach ($main as $key => $value) {
            if ($key === 'paths' && is_array($value)) {
                $base['paths'] = $this->mergePathMaps($base['paths'] ?? [], $value);
            } elseif ($key === 'components' && is_array($value)) {
                $base['components'] = $this->mergeComponentMaps($base['components'] ?? [], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Merges one parsed fragment into the working document.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $fragment
     *
     * @return array<string, mixed>
     */
    protected function mergeFragment(array $doc, array $fragment): array
    {
        if (isset($fragment['paths']) && is_array($fragment['paths'])) {
            $doc['paths'] = $this->mergePathMaps($doc['paths'] ?? [], $fragment['paths']);
        }
        if (isset($fragment['components']) && is_array($fragment['components'])) {
            $doc['components'] = $this->mergeComponentMaps($doc['components'] ?? [], $fragment['components']);
        }

        foreach (['openapi', 'info', 'servers', 'security', 'tags', 'externalDocs'] as $top) {
            if (isset($fragment[$top]) && !isset($doc[$top])) {
                $doc[$top] = $fragment[$top];
            }
        }

        return $doc;
    }

    /**
     * Merges path maps so later files override the same path and HTTP method pair.
     *
     * @param array<string, mixed> $into
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    protected function mergePathMaps(array $into, array $more): array
    {
        foreach ($more as $pathKey => $operations) {
            if (!is_array($operations)) {
                $into[$pathKey] = $operations;
                continue;
            }
            if (!isset($into[$pathKey]) || !is_array($into[$pathKey])) {
                $into[$pathKey] = $operations;
                continue;
            }
            foreach ($operations as $method => $def) {
                $into[$pathKey][$method] = $def;
            }
        }
        ksort($into);

        return $into;
    }

    /**
     * Merges component buckets with later fragment items overriding earlier keys in the same bucket.
     *
     * @param array<string, mixed> $into
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    protected function mergeComponentMaps(array $into, array $more): array
    {
        foreach ($more as $bucket => $items) {
            if (!is_array($items)) {
                $into[$bucket] = $items;
                continue;
            }
            if (!isset($into[$bucket]) || !is_array($into[$bucket])) {
                $into[$bucket] = $items;
                continue;
            }
            $into[$bucket] = array_merge($into[$bucket], $items);
        }
        ksort($into);

        return $into;
    }

    /**
     * Drops non-string keys so parsed YAML behaves like an object map.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function normalizeObjectMap(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Dereferences local component refs in the merged document.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function dereference(array $document): array
    {
        $resolved = $this->resolveNode($document, $document, [], 0);
        if (!is_array($resolved)) {
            RuntimeException::raise('Dereferenced document must be an object-like array.');
        }

        return $resolved;
    }

    /**
     * Resolves one node recursively, expanding local <code>$ref</code> pointers and preserving sibling overrides.
     *
     * @param mixed $node
     * @param array<string, mixed> $root
     * @param array<string, true> $stack
     *
     * @return mixed
     */
    protected function resolveNode(mixed $node, array $root, array $stack, int $depth): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if ($depth > static::MAX_DEREF_DEPTH) {
            RuntimeException::raise('OpenAPI $ref dereference exceeded maximum depth {depth}.', [
                'depth' => static::MAX_DEREF_DEPTH,
            ]);
        }

        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $ref = $node['$ref'];
            if (str_starts_with($ref, '#/')) {
                if (isset($stack[$ref])) {
                    RuntimeException::raise('OpenAPI $ref cycle detected at {ref}.', ['ref' => $ref]);
                }
                $target = $this->resolvePointer($root, substr($ref, 2));
                $nextStack = $stack;
                $nextStack[$ref] = true;
                $resolvedTarget = $this->resolveNode($target, $root, $nextStack, $depth + 1);

                $siblings = $node;
                unset($siblings['$ref']);
                if ($siblings === []) {
                    return $resolvedTarget;
                }
                $resolvedSiblings = $this->resolveNode($siblings, $root, $nextStack, $depth + 1);
                if (is_array($resolvedTarget) && is_array($resolvedSiblings)) {
                    return array_replace_recursive($resolvedTarget, $resolvedSiblings);
                }

                return $resolvedTarget;
            }
        }

        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = $this->resolveNode($value, $root, $stack, $depth + 1);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $root
     *
     * @return mixed
     */
    protected function resolvePointer(array $root, string $pointerWithoutPrefix): mixed
    {
        $current = $root;
        if ($pointerWithoutPrefix === '') {
            return $current;
        }

        $segments = explode('/', $pointerWithoutPrefix);
        foreach ($segments as $raw) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $raw);
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                RuntimeException::raise('Broken OpenAPI local $ref pointer: #/{pointer}', [
                    'pointer' => $pointerWithoutPrefix,
                ]);
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
