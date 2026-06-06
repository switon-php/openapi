<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use Switon\Core\ClassName;
use Switon\Core\Naming;

use function basename;

/**
 * Default {@see OperationIdInterface}; algorithm matches {@see \Switon\Routing\HandlerId::getId()}.
 *
 * @see \Switon\Core\ClassName::dotId()
 * @see \Switon\Core\Naming::kebab()
 */
class OperationId implements OperationIdInterface
{
    public function getOperationId(string $controller, string $action): string
    {
        return ClassName::dotId($controller, compact: true) . '::' . Naming::kebab(basename($action, 'Action'));
    }
}
