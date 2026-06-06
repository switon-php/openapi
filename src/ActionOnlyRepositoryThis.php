<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use ReflectionException;

use function array_slice;
use function class_exists;
use function file;
use function implode;
use function interface_exists;
use function ltrim;
use function preg_match_all;
use function str_ends_with;
use function str_starts_with;

use const PREG_OFFSET_CAPTURE;

/**
 * Static check: an action method’s body only uses <code>$this->…</code> as <strong>property</strong> roots (e.g. <code>$this->repo->query()</code>); any direct <code>$this->method(</code> invocation fails (may reach HTTP or non-repository code). Every distinct property root must be repository-typed (FQCN ends with <code>Repository</code>; fixed convention in the default implementation).
 *
 * Guidance: Conservative input for optional runtime probes; not bound to <code>open-api:sync</code>. Untyped properties, unknown properties, and union/intersection types that include a non-repository fail the check.
 *
 * @see \Switon\OpenApi\PhpReturnSchema
 */
class ActionOnlyRepositoryThis
{
    /** Class or interface FQCN must end with this suffix (convention only; override in a subclass if needed). */
    protected const string REPOSITORY_NAME_SUFFIX = 'Repository';

    public function onlyUsesRepositoryThisPropertiesByName(string $controllerClass, string $method): bool
    {
        if (!class_exists($controllerClass)) {
            return false;
        }

        try {
            $m = new ReflectionMethod($controllerClass, $method);
        } catch (ReflectionException) {
            return false;
        }

        return $this->onlyUsesRepositoryThisProperties($m);
    }

    public function onlyUsesRepositoryThisProperties(ReflectionMethod $method): bool
    {
        $controller = $method->getDeclaringClass();
        if ($controller->isInterface()) {
            return false;
        }

        $body = $this->readMethodBodySource($method);
        if ($body === null) {
            return false;
        }

        $names = $this->listThisPropertyRootsForRepositoryRule($body);
        if ($names === null || $names === []) {
            return false;
        }

        foreach ($names as $propName) {
            $prop = $this->resolveProperty($controller, $propName);
            if ($prop === null) {
                return false;
            }
            $ptype = $prop->getType();
            if ($ptype === null || !$this->typeIsRepositoryOnly($ptype, $prop->getDeclaringClass())) {
                return false;
            }
        }

        return true;
    }

    protected function readMethodBodySource(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if ($file === false || !is_file($file)) {
            return null;
        }
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if ($start === false || $end === false || $start > $end) {
            return null;
        }
        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * Collect distinct <code>$this->name</code> roots used as properties. If any match is a direct <code>$this->name(</code> call on the controller, returns null (disallowed).
     *
     * @return list<string>|null null when a <code>$this->method(</code>-style call is present
     */
    protected function listThisPropertyRootsForRepositoryRule(string $source): ?array
    {
        $n = preg_match_all('/\$this->([a-zA-Z_]\w*)/', $source, $matches, PREG_OFFSET_CAPTURE);
        if ($n === false || $n === 0) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($matches[0] as $i => $pair) {
            [$fullText, $offset] = $pair;
            [$name] = $matches[1][$i];
            $after = ltrim(substr($source, $offset + strlen($fullText)));
            if (str_starts_with($after, '(')) {
                return null;
            }
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @param ReflectionClass<object> $controller
     */
    protected function resolveProperty(ReflectionClass $controller, string $propertyName): ?ReflectionProperty
    {
        for ($c = $controller; $c !== false;) {
            if (!$c->hasProperty($propertyName)) {
                $c = $c->getParentClass();
                continue;
            }
            try {
                return $c->getProperty($propertyName);
            } catch (ReflectionException) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $propertyDeclaringClass
     */
    protected function typeIsRepositoryOnly(
        ReflectionType  $type,
        ReflectionClass $propertyDeclaringClass,
    ): bool {
        if ($type instanceof ReflectionIntersectionType) {
            return false;
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType && $t->getName() === 'null') {
                    continue;
                }
                if (!$t instanceof ReflectionNamedType || !$this->namedTypeIsRepository($t, $propertyDeclaringClass)) {
                    return false;
                }
            }

            return true;
        }
        if ($type instanceof ReflectionNamedType) {
            return $this->namedTypeIsRepository($type, $propertyDeclaringClass);
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $propertyDeclaringClass
     */
    protected function namedTypeIsRepository(
        ReflectionNamedType $type,
        ReflectionClass     $propertyDeclaringClass,
    ): bool {
        if ($type->isBuiltin()) {
            return false;
        }

        $name = $type->getName();
        $parent = $propertyDeclaringClass->getParentClass();
        $resolved = match ($name) {
            'self' => $propertyDeclaringClass->getName(),
            'static' => $propertyDeclaringClass->getName(),
            'parent' => $parent !== false ? $parent->getName() : null,
            default => $name,
        };
        if ($resolved === null || $resolved === '') {
            return false;
        }

        if (!str_ends_with($resolved, static::REPOSITORY_NAME_SUFFIX)) {
            return false;
        }

        return class_exists($resolved) || interface_exists($resolved);
    }
}
