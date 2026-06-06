<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use DateTimeInterface;
use ReflectionClass;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Switon\Http\ResponseInterface;
use Generator;
use ReflectionEnumBackedCase;
use ReflectionEnumUnitCase;
use Throwable;

use function array_map;
use function class_exists;
use function enum_exists;
use function interface_exists;
use function is_a;
use function is_subclass_of;

/**
 * Default {@see PhpReturnSchemaInterface}: object schema from public instance properties; enums and scalars when returned directly.
 *
 * @see \Switon\OpenApi\YamlSnippetEmitter
 */
class PhpReturnSchema implements PhpReturnSchemaInterface
{
    protected const int MAX_DEPTH = 5;

    public function inferSchemaFromReturnType(ReflectionMethod $method): ?array
    {
        $type = $method->getReturnType();
        if ($type === null) {
            return null;
        }

        $declaring = $method->getDeclaringClass();

        return $this->schemaForReflectionType($type, $declaring, [], 0);
    }

    /**
     * @param ReflectionClass<object> $declaringClass
     * @param array<string, true> $expandingClasses
     *
     * @return array<string, mixed>|null
     */
    protected function schemaForReflectionType(
        ReflectionType  $type,
        ReflectionClass $declaringClass,
        array           $expandingClasses,
        int             $depth,
    ): ?array {
        if ($type instanceof ReflectionIntersectionType) {
            return null;
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $u) {
                if ($u instanceof ReflectionNamedType && $u->getName() === 'null') {
                    continue;
                }
                $s = $this->schemaForReflectionType($u, $declaringClass, $expandingClasses, $depth);
                if ($s !== null) {
                    return $s;
                }
            }

            return null;
        }
        if ($type instanceof ReflectionNamedType) {
            return $this->schemaForNamedType($type, $declaringClass, $expandingClasses, $depth);
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $declaringClass
     * @param array<string, true> $expandingClasses
     *
     * @return array<string, mixed>|null
     */
    protected function schemaForNamedType(
        ReflectionNamedType $type,
        ReflectionClass     $declaringClass,
        array               $expandingClasses,
        int                 $depth,
    ): ?array {
        $name = $type->getName();
        if ($type->isBuiltin()) {
            return $this->schemaForBuiltinReturn($name);
        }

        $className = $this->resolveClassName($name, $declaringClass);
        if ($className === null) {
            return null;
        }

        if (interface_exists($className)) {
            return null;
        }

        if ($this->shouldSkipConcreteReturnType($className)) {
            return null;
        }

        if (enum_exists($className)) {
            return $this->schemaForEnum($className);
        }

        if (!class_exists($className)) {
            return null;
        }

        if (is_a($className, DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if ($depth >= self::MAX_DEPTH) {
            return ['type' => 'object', 'description' => 'max expansion depth'];
        }

        if (isset($expandingClasses[$className])) {
            return ['type' => 'object', 'description' => 'circular reference'];
        }

        $expandingClasses[$className] = true;

        try {
            return $this->schemaForObjectClass($className, $expandingClasses, $depth);
        } finally {
            unset($expandingClasses[$className]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function schemaForBuiltinReturn(string $name): ?array
    {
        return match ($name) {
            'void', 'never' => null,
            'mixed' => null,
            'null' => null,
            'false' => null,
            'true' => ['type' => 'boolean'],
            'array', 'iterable' => ['type' => 'array'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            'object' => ['type' => 'object'],
            default => null,
        };
    }

    /**
     * @param ReflectionClass<object> $declaringClass
     */
    protected function resolveClassName(string $name, ReflectionClass $declaringClass): ?string
    {
        $parent = $declaringClass->getParentClass();

        return match ($name) {
            'self' => $declaringClass->getName(),
            'static' => $declaringClass->getName(),
            'parent' => $parent !== false ? $parent->getName() : null,
            default => $name,
        };
    }

    protected function shouldSkipConcreteReturnType(string $className): bool
    {
        return is_a($className, ResponseInterface::class, true)
            || (
                interface_exists(\Psr\Http\Message\ResponseInterface::class)
                && is_a($className, \Psr\Http\Message\ResponseInterface::class, true)
            )
            || is_subclass_of($className, Throwable::class)
            || is_a($className, Generator::class, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function schemaForEnum(string $className): array
    {
        if (!enum_exists($className)) {
            return ['type' => 'string'];
        }

        $r = new ReflectionEnum($className);
        if ($r->isBacked()) {
            $backing = $r->getBackingType();
            if (!$backing instanceof ReflectionNamedType) {
                return ['type' => 'string'];
            }

            return match ($backing->getName()) {
                'int' => ['type' => 'integer'],
                'string' => ['type' => 'string'],
                default => ['type' => 'string'],
            };
        }

        $cases = array_map(
            static fn (ReflectionEnumUnitCase|ReflectionEnumBackedCase $c): string => $c->getName(),
            $r->getCases(),
        );

        return [
            'type' => 'string',
            'enum' => $cases,
        ];
    }

    /**
     * @param array<string, true> $expandingClasses
     *
     * @return array<string, mixed>
     */
    protected function schemaForObjectClass(string $className, array $expandingClasses, int $depth): array
    {
        if (!class_exists($className)) {
            return ['type' => 'object'];
        }

        $r = new ReflectionClass($className);
        $properties = [];
        foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $pname = $prop->getName();
            $ptype = $prop->getType();
            if ($ptype === null) {
                $properties[$pname] = [];

                continue;
            }
            $child = $this->schemaForReflectionType($ptype, $r, $expandingClasses, $depth + 1);
            $properties[$pname] = $child ?? [];
        }

        $out = ['type' => 'object'];
        if ($properties !== []) {
            $out['properties'] = $properties;
        }

        return $out;
    }
}
