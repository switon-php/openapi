<?php

declare(strict_types=1);

namespace Switon\OpenApi;

/**
 * Collects HTTP routes from attribute-mapped controllers for OpenAPI scaffolding.
 *
 * Guidance: Controller discovery uses {@see \Switon\Routing\ControllerScannerInterface} (<code>$paths</code> on {@see \Switon\Routing\ControllerScanner}). Patterns include the configured {@see \Switon\Routing\RouterInterface} prefix the same way as <code>router:list</code>; conditional <code>?…</code> prefixes are not expanded.
 *
 * Each row: <code>handlerId</code> matches {@see \Switon\Routing\HandlerIdInterface::getId()} (RBAC key); <code>operationId</code> equals <code>handlerId</code>. Only {@see \Switon\Routing\Attribute\ViewMapping} is skipped; <code>ViewGetMapping</code> / <code>ViewPostMapping</code> / … each contribute one operation; {@see \Switon\Routing\Attribute\Viewable} synthetic GET is not duplicated.
 *
 * @see \Switon\OpenApi\RouteCollector
 * @see \Switon\Routing\RouteRegistrar
 */
interface RouteCollectorInterface
{
    /**
     * @return list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}>
     */
    public function collect(): array;
}
