<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\MappingInterface;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewMapping;
use Switon\Routing\ControllerScannerInterface;
use Switon\Routing\MappingPathInterface;
use Switon\Routing\RouterInterface;

use function array_map;
use function str_starts_with;

/**
 * Collects OpenAPI-relevant routes from HTTP-discovered controllers using the same prefix and pattern rules as the router.
 *
 * Mirrors {@see \Switon\Routing\RouteRegistrar} per controller without touching the router table. Omits {@see \Switon\Routing\Attribute\ViewMapping} only; other view attributes emit one primary route each (no duplicate HTML GET). Optional <code>responseSchema</code> per row comes from {@see ResponseSchemaGuesserInterface}.
 *
 * @see \Switon\Routing\ControllerScannerInterface
 * @see \Switon\Routing\MappingPathInterface
 * @see \Switon\Routing\RouterInterface
 */
class RouteCollector implements RouteCollectorInterface
{
    #[Autowired] protected ControllerScannerInterface $controllerScanner;

    #[Autowired] protected MappingPathInterface $mappingPath;

    #[Autowired] protected RouterInterface $router;

    #[Autowired] protected OperationIdInterface $operationIds;

    #[Autowired] protected ResponseSchemaGuesserInterface $responseSchemaGuesser;

    /**
     * Collects OpenAPI route rows from discovered controller mappings.
     */
    public function collect(): array
    {
        $out = [];
        foreach ($this->controllerScanner->getControllers() as $className) {
            if (!class_exists($className)) {
                continue;
            }
            $rClass = new ReflectionClass($className);
            $requestMappings = $rClass->getAttributes(RequestMapping::class);
            if ($requestMappings === []) {
                continue;
            }
            $this->scanController($rClass, $requestMappings[0]->newInstance(), $out);
        }

        return $out;
    }

    /**
     * @param ReflectionClass<object> $rClass
     * @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}> $out
     */
    protected function scanController(ReflectionClass $rClass, RequestMapping $requestMapping, array &$out): void
    {
        $controller = $rClass->getName();
        $prefixes = $this->mappingPath->resolveControllerPrefixes($rClass, $requestMapping);

        foreach ($rClass->getMethods(ReflectionMethod::IS_PUBLIC) as $rMethod) {
            $action = $rMethod->getName();
            foreach ($this->resolveMethodMappings($rMethod) as $mapping) {
                foreach ($this->mappingPath->resolveMethodPatterns($prefixes, $action, $mapping) as $pattern) {
                    $this->appendRoute($rMethod, $controller, $pattern, $mapping, $out);
                }
            }
        }
    }

    /**
     * Resolves mapping attributes declared on one public action method.
     *
     * @return list<MappingInterface>
     */
    protected function resolveMethodMappings(ReflectionMethod $rMethod): array
    {
        $attributes = $rMethod->getAttributes(MappingInterface::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return [];
        }

        return array_map(
            static fn (ReflectionAttribute $attribute) => $attribute->newInstance(),
            $attributes,
        );
    }

    /**
     * Appends one collected route row, including an optional inferred response schema.
     *
     * @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}> $out
     */
    protected function appendRoute(
        ReflectionMethod $rMethod,
        string           $controller,
        string           $pattern,
        MappingInterface $mapping,
        array            &$out,
    ): void {
        $action = $rMethod->getName();
        $handler = $controller . '::' . $action;
        $handlerId = $this->operationIds->getOperationId($controller, $action);
        $fullPattern = $this->fullRoutePattern($pattern);

        // Only #[ViewMapping] is omitted (generic HTML page GET). ViewGetMapping / ViewPostMapping / … each emit one primary route; no synthetic GET from Viewable.
        if ($mapping instanceof ViewMapping) {
            return;
        }

        $row = [
            'verb' => $mapping->getVerb(),
            'pattern' => $fullPattern,
            'handler' => $handler,
            'handlerId' => $handlerId,
            'operationId' => $handlerId,
        ];
        $schema = $this->responseSchemaGuesser->guess($rMethod);
        if ($schema !== null) {
            $row['responseSchema'] = $schema;
        }
        $out[] = $row;
    }

    /**
     * Prefix + pattern, matching {@see \Switon\Routing\Command\RouterCommand::listAction()} JSON shape.
     */
    protected function fullRoutePattern(string $pattern): string
    {
        $routerPrefix = $this->router->getPrefix();
        if ($routerPrefix === '') {
            return $pattern;
        }

        if (str_starts_with($routerPrefix, '?')) {
            return $routerPrefix . $pattern;
        }

        return $routerPrefix . $pattern;
    }
}
