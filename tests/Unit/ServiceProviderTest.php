<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\ContainerInterface;
use Switon\Core\PathAliasInterface;
use Switon\OpenApi\ServiceProvider;
use Switon\Testing\Container;
use Switon\Testing\PackagePathAssert;

final class ServiceProviderTest extends TestCase
{
    public function testRegisterIsNoop(): void
    {
        $provider = new ServiceProvider();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('set');

        $provider->register($container);

        $this->addToAssertionCount(1);
    }

    public function testContainerRegistersOpenApiResourceAliasFromProviderAttribute(): void
    {
        $container = new Container();
        $pathAlias = $container->get(PathAliasInterface::class);
        $resourceRoot = $pathAlias->get('@switon.openapi.resources');
        $this->assertIsString($resourceRoot);
        PackagePathAssert::assertSameAsPackagePath(ServiceProvider::class, $resourceRoot, 'resources');
    }
}
