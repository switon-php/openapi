<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Switon\OpenApi\ActionOnlyRepositoryThis;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositoryBuiltinFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositoryChildFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositoryControllerInterface;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositoryInheritedRepositoryChildFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositoryInterfaceFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositoryIntersectionFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositoryMissingPropertyFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositorySelfRepository;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositoryUnionFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\ActionOnlyRepositoryUnionWithNonRepositoryFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\InvokesRepositoryAsCallFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\InvokesThisMethodWithRepoFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\OnlyMethodCallControllerFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\RepoOnlyControllerFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\RepoPlusOtherPropertyControllerFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\UntypedPropertyControllerFixture;

final class ActionOnlyRepositoryThisTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string, 2: bool}>
     */
    public static function repositoryRuleCases(): array
    {
        return [
            'repository property only' => [RepoOnlyControllerFixture::class, 'indexAction', true],
            'non repository property' => [RepoPlusOtherPropertyControllerFixture::class, 'indexAction', false],
            'this method call' => [InvokesThisMethodWithRepoFixture::class, 'indexAction', false],
            'repository call syntax' => [InvokesRepositoryAsCallFixture::class, 'indexAction', false],
            'only method calls' => [OnlyMethodCallControllerFixture::class, 'saveAction', false],
            'untyped property' => [UntypedPropertyControllerFixture::class, 'touchAction', false],
            'self typed repository' => [ActionOnlyRepositorySelfRepository::class, 'indexAction', true],
            'parent typed repository' => [ActionOnlyRepositoryChildFixture::class, 'indexAction', true],
            'inherited repository property' => [ActionOnlyRepositoryInheritedRepositoryChildFixture::class, 'indexAction', true],
            'interface typed repository' => [ActionOnlyRepositoryInterfaceFixture::class, 'indexAction', true],
            'nullable union repository' => [ActionOnlyRepositoryUnionFixture::class, 'indexAction', true],
            'mixed union repository' => [ActionOnlyRepositoryUnionWithNonRepositoryFixture::class, 'indexAction', false],
            'intersection typed repository' => [ActionOnlyRepositoryIntersectionFixture::class, 'indexAction', false],
            'builtin typed property' => [ActionOnlyRepositoryBuiltinFixture::class, 'indexAction', false],
            'missing property' => [ActionOnlyRepositoryMissingPropertyFixture::class, 'indexAction', false],
            'interface controller' => [ActionOnlyRepositoryControllerInterface::class, 'indexAction', false],
        ];
    }

    #[DataProvider('repositoryRuleCases')]
    public function testOnlyUsesRepositoryThisProperties(string $fixtureClass, string $method, bool $expected): void
    {
        $rule = new ActionOnlyRepositoryThis();
        $this->assertSame($expected, $rule->onlyUsesRepositoryThisPropertiesByName($fixtureClass, $method));
    }
}
