<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use Switon\Core\Attribute\ResourceAlias;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Loads the OpenAPI package; default services use container auto-mapping for this namespace.
 *
 * Guidance:
 * - rely on namespace auto-mapping for the default <code>XxxInterface</code> to <code>Xxx</code> bindings
 * - keep this provider as a resource alias anchor and lifecycle no-op unless package wiring truly needs custom registration
 *
 * Road-signs:
 * - CLI: {@see Command\OpenApiCommand}
 * - document build/export: {@see DocumentBuilderInterface}
 *
 * @see \Switon\Core\ServiceProviderInterface
 * @see \Switon\OpenApi\YmlSyncInterface
 */
#[ResourceAlias]
class ServiceProvider implements ServiceProviderInterface
{
    /** Keeps registration empty because default OpenAPI services are resolved by container convention. */
    public function register(ContainerInterface $container): void
    {
    }

    /** No-op hook kept for service provider lifecycle parity. */
    public function boot(): void
    {
    }
}
