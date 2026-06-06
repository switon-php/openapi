<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\OpenApi\OperationId;

/**
 * Matches {@see \Switon\Http\HandlerId::getId()} / {@see \Switon\Core\Tests\Unit\ClassNameTest} composeHttpHandlerId cases.
 */
final class OperationIdTest extends TestCase
{
    private OperationId $ids;

    protected function setUp(): void
    {
        $this->ids = new OperationId();
    }

    public function testMatchesHandlerIdExamples(): void
    {
        $this->assertSame('user::index', $this->ids->getOperationId('App\Controller\UserController', 'indexAction'));
        $this->assertSame('menu.item::index', $this->ids->getOperationId('App\Areas\Menu\Controller\ItemController', 'indexAction'));
        $this->assertSame('admin.user::create', $this->ids->getOperationId('App\Areas\Admin\Controller\UserController', 'createAction'));
        $this->assertSame('user::list', $this->ids->getOperationId('App\Controller\UserController', 'list'));
        $this->assertSame(
            'rbac.role-permission::assign',
            $this->ids->getOperationId('App\Areas\Rbac\Controller\RolePermissionController', 'assignAction'),
        );
        $this->assertSame('admin.account::captcha', $this->ids->getOperationId('App\Areas\Admin\Controller\AccountController', 'captchaAction'));
    }

    public function testPreservesActionThatDoesNotUseActionSuffix(): void
    {
        $this->assertSame(
            'status::health-check',
            $this->ids->getOperationId('App\Controller\StatusController', 'healthCheck')
        );
    }
}
