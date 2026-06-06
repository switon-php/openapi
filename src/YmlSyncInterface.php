<?php

declare(strict_types=1);

namespace Switon\OpenApi;

/**
 * Writes <code>openapi/controllers/*.yml</code> fragments from {@see RouteCollectorInterface} output (file stem = handler id: segment before <code>::</code> in <code>operationId</code>) and auto-creates missing <code>openapi/components/entity.{handler-id-prefix}.yml</code> files.
 *
 * @see \Switon\OpenApi\YmlSync
 */
interface YmlSyncInterface
{
    /**
     * @param string $root Absolute OpenAPI root directory (trailing slash optional)
     * @param string $controller Optional handler controller FQCN filter (exact match); empty = all controllers
     * @param string $action Optional handler method name filter (e.g. <code>indexAction</code>, exact match); empty = all actions
     * @param bool $tail When true, new path blocks are appended at the end of the <code>paths</code> section (before <code>components</code> / EOF); when false, lexicographic order among path keys (default). CLI: <code>--tail</code>.
     *
     * @return array{written: list<string>, warnings: list<string>} Written paths; warnings when a file had missing ops but append was skipped
     */
    public function sync(string $root, string $controller, string $action, bool $tail = false): array;
}
