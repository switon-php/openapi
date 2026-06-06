<?php

declare(strict_types=1);

namespace Switon\OpenApi;

/**
 * Builds OpenAPI <code>operationId</code> strings aligned with HTTP handler IDs.
 *
 * Guidance: Use the same controller FQCN and PHP action method name as routing (<code>Controller::action</code>); output matches {@see \Switon\Routing\HandlerIdInterface::getId()} for RBAC and docs.
 *
 * @see \Switon\OpenApi\OperationId
 * @see \Switon\Routing\HandlerId
 */
interface OperationIdInterface
{
    /**
     * Stable id for one action; equals {@see \Switon\Routing\HandlerIdInterface::getId()} with identical inputs.
     *
     * @param string $controller Controller class FQCN
     * @param string $action PHP method name (e.g. <code>indexAction</code>)
     */
    public function getOperationId(string $controller, string $action): string;
}
