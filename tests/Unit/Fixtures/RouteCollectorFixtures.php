<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewMapping;
use BadMethodCallException;

#[RequestMapping(['/api/admin/', '/api/v2/admin/'])]
final class RouteCollectorControllerFixture
{
    #[GetMapping('/users/{id}')]
    public function showAction(): SampleGroup
    {
        throw new BadMethodCallException('fixture');
    }

    #[PostMapping(['', '/bulk'])]
    public function saveAction(): array
    {
        throw new BadMethodCallException('fixture');
    }

    #[ViewMapping('/page')]
    public function pageAction(): void
    {
    }
}

final class RouteCollectorPlainControllerFixture
{
    public function indexAction(): void
    {
    }
}
