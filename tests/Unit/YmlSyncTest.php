<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\Filesystem;
use Switon\Core\PathAlias;
use Switon\OpenApi\RouteCollectorInterface;
use Switon\OpenApi\YmlSync;
use Switon\Yaml\YamlReader;

final class YmlSyncTest extends TestCase
{
    public function testControllerYamlFileNameUsesOperationIdPrefix(): void
    {
        $sync = new class () extends YmlSync {
            public function fileName(array $routes): string
            {
                return $this->controllerYamlFileName($routes);
            }
        };
        $routes = [
            [
                'verb' => 'GET',
                'pattern' => '/snowflake',
                'handler' => 'App\Controller\TimeController::snowflakeAction',
                'handlerId' => 'time::snowflake',
                'operationId' => 'time::snowflake',
            ],
        ];
        $this->assertSame('time.yml', $sync->fileName($routes));
    }

    public function testControllerYamlFileNameAllowsDotSeparatedHandlerId(): void
    {
        $sync = new class () extends YmlSync {
            public function fileName(array $routes): string
            {
                return $this->controllerYamlFileName($routes);
            }
        };
        $routes = [
            [
                'verb' => 'GET',
                'pattern' => '/x',
                'handler' => 'App\Areas\Admin\Controller\AccountController::indexAction',
                'handlerId' => 'admin.account::index',
                'operationId' => 'admin.account::index',
            ],
        ];
        $this->assertSame('admin.account.yml', $sync->fileName($routes));
    }

    public function testControllerYamlFileNameFallsBackToFqcnWhenPrefixMissing(): void
    {
        $sync = new class () extends YmlSync {
            public function fileName(array $routes): string
            {
                return $this->controllerYamlFileName($routes);
            }
        };
        $routes = [
            [
                'verb' => 'GET',
                'pattern' => '/x',
                'handler' => 'App\Controller\XController::aAction',
                'handlerId' => '::a',
                'operationId' => '::a',
            ],
        ];
        $this->assertSame('App__Controller__XController.yml', $sync->fileName($routes));
    }

    public function testSyncAppendsMissingOperationWithoutTouchingUnchangedFiles(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));
        $this->writeEntitySchema($tmp, 'demo');

        $existing = <<<'YAML'
paths:
  /alpha:
    get:
      operationId: 'demo::alpha'
      summary: 'App\Controller\DemoController::alphaAction'
      responses:
        '200':
          description: OK

YAML;
        file_put_contents($tmp . '/controllers/demo.yml', $existing);

        $rows = [
            [
                'verb' => 'GET',
                'pattern' => '/alpha',
                'handler' => 'App\Controller\DemoController::alphaAction',
                'handlerId' => 'demo::alpha',
                'operationId' => 'demo::alpha',
            ],
            [
                'verb' => 'POST',
                'pattern' => '/beta',
                'handler' => 'App\Controller\DemoController::betaAction',
                'handlerId' => 'demo::beta',
                'operationId' => 'demo::beta',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            /** @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string}> $rows */
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '');
        $written = $result['written'];
        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $written);
        $out = file_get_contents($tmp . '/controllers/demo.yml');
        $this->assertIsString($out);
        $this->assertStringContainsString('/alpha', $out);
        $this->assertStringContainsString('/beta', $out);
        $this->assertStringContainsString('post:', $out);
        $this->assertStringContainsString('demo::beta', $out);
        $this->assertStringContainsString("# \$ref: '#/components/schemas/response.simple'", $out);
        $this->assertStringContainsString("# \$ref: '#/components/schemas/response.page'", $out);
        $this->assertStringContainsString("# \$ref: '#/components/schemas/entity.demo'", $out);

        $again = $sync->sync($tmp, '', '');
        $this->assertSame([], $again['written']);
        $this->assertSame([], $again['warnings']);

        $this->rrmdir($tmp);
    }

    public function testSyncWritesPathParametersForNewControllerFile(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_params_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));
        $this->writeEntitySchema($tmp, 'items');

        $rows = [
            [
                'verb' => 'GET',
                'pattern' => '/items/{id}',
                'handler' => 'App\Controller\ItemsController::getAction',
                'handlerId' => 'items::get',
                'operationId' => 'items::get',
            ],
            [
                'verb' => 'DELETE',
                'pattern' => '/items/{id}',
                'handler' => 'App\Controller\ItemsController::deleteAction',
                'handlerId' => 'items::delete',
                'operationId' => 'items::delete',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            /** @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string}> $rows */
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '');
        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $result['written']);
        $out = file_get_contents($tmp . '/controllers/items.yml');
        $this->assertIsString($out);
        $this->assertStringNotContainsString("\ncomponents:\n", $out);
        $this->assertStringContainsString('/items/{id}:', $out);
        $this->assertStringContainsString('parameters:', $out);
        $this->assertStringContainsString("name: 'id'", $out);
        $this->assertStringContainsString('in: path', $out);
        $this->assertStringContainsString('required: true', $out);
        $this->assertStringContainsString('type: string', $out);
        $this->assertSame(2, substr_count($out, 'parameters:'), 'each operation under the path gets its own parameters block');

        $this->rrmdir($tmp);
    }

    public function testSyncAppendsOperationWithPathParameters(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_params2_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));
        $this->writeEntitySchema($tmp, 'demo');

        $existing = <<<'YAML'
paths:
  /widgets/{widgetId}/parts/{partId}:
    get:
      operationId: 'demo::getPart'
      summary: 'App\Controller\DemoController::getPartAction'
      parameters:
        -
          name: 'widgetId'
          in: path
          required: true
          schema:
            type: string
        -
          name: 'partId'
          in: path
          required: true
          schema:
            type: string
      responses:
        '200':
          description: OK

YAML;
        file_put_contents($tmp . '/controllers/demo.yml', $existing);

        $rows = [
            [
                'verb' => 'GET',
                'pattern' => '/widgets/{widgetId}/parts/{partId}',
                'handler' => 'App\Controller\DemoController::getPartAction',
                'handlerId' => 'demo::getPart',
                'operationId' => 'demo::getPart',
            ],
            [
                'verb' => 'PATCH',
                'pattern' => '/widgets/{widgetId}/parts/{partId}',
                'handler' => 'App\Controller\DemoController::patchPartAction',
                'handlerId' => 'demo::patchPart',
                'operationId' => 'demo::patchPart',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            /** @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string}> $rows */
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '');
        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $result['written']);
        $out = file_get_contents($tmp . '/controllers/demo.yml');
        $this->assertIsString($out);
        $this->assertStringContainsString('patch:', $out);
        $this->assertStringContainsString('demo::patchPart', $out);
        $this->assertStringContainsString("name: 'widgetId'", $out);
        $this->assertStringContainsString("name: 'partId'", $out);

        $this->rrmdir($tmp);
    }

    public function testSyncWritesApplicationJsonSchemaWhenRouteRowHasResponseSchema(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_schema_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));
        $this->writeEntitySchema($tmp, 'group');

        $rows = [
            [
                'verb' => 'GET',
                'pattern' => '/group/{id}',
                'handler' => 'App\Controller\GroupController::editAction',
                'handlerId' => 'group::edit',
                'operationId' => 'group::edit',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            /** @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string, responseSchema?: array<string, mixed>}> $rows */
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '');
        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $result['written']);
        $out = file_get_contents($tmp . '/controllers/group.yml');
        $this->assertIsString($out);
        $this->assertStringContainsString('application/json:', $out);
        $this->assertStringContainsString('schema:', $out);
        $this->assertStringContainsString('type: object', $out);
        $this->assertStringContainsString('properties:', $out);
        $this->assertStringContainsString('Optional: replace inline schema with shared wrappers', $out);
        $this->assertStringContainsString("# \$ref: '#/components/schemas/response.simple'", $out);
        $this->assertStringContainsString("# \$ref: '#/components/schemas/entity.group'", $out);

        $this->rrmdir($tmp);
    }

    public function testSyncWarnsAndSkipsWhenFileContainsTabs(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_tabs_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));
        $this->writeEntitySchema($tmp, 'demo');

        $tabbed = "paths:\n\t  /a:\n    get:\n      operationId: 'x::a'\n      summary: 'H::a'\n      responses:\n        '200':\n          description: OK\n";
        file_put_contents($tmp . '/controllers/demo.yml', $tabbed);

        $rows = [
            [
                'verb' => 'POST',
                'pattern' => '/a',
                'handler' => 'App\Controller\DemoController::aAction',
                'handlerId' => 'demo::a',
                'operationId' => 'demo::a',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            /** @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string}> $rows */
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '');
        $this->assertSame([], $result['written']);
        $this->assertNotSame([], $result['warnings']);
        $this->assertStringContainsString('tabs', implode("\n", $result['warnings']));

        $this->rrmdir($tmp);
    }

    public function testSyncAutoCreatesEntityFileWhenMissing(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_entity_missing_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));

        $rows = [
            [
                'verb' => 'GET',
                'pattern' => '/users',
                'handler' => 'App\Controller\UserController::indexAction',
                'handlerId' => 'user::index',
                'operationId' => 'user::index',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            /** @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string}> $rows */
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '');
        $this->assertCount(2, $result['written']);
        $this->assertSame([], $result['warnings']);
        $this->assertFileExists($tmp . '/components/entity.user.yml');
        $entity = file_get_contents($tmp . '/components/entity.user.yml');
        $this->assertIsString($entity);
        $this->assertStringContainsString("entity.user", $entity);

        $this->rrmdir($tmp);
    }

    public function testSyncWarnsWhenExistingYamlHasNoPathsSection(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_nopaths_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));
        $this->writeEntitySchema($tmp, 'demo');

        $existing = "openapi: 3.0.0\ninfo:\n  title: Demo\n";
        file_put_contents($tmp . '/controllers/demo.yml', $existing);

        $rows = [
            [
                'verb' => 'GET',
                'pattern' => '/orphan',
                'handler' => 'App\Controller\DemoController::orphanAction',
                'handlerId' => 'demo::orphan',
                'operationId' => 'demo::orphan',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '');
        $this->assertSame([], $result['written']);
        $this->assertNotSame([], $result['warnings']);
        $this->assertStringContainsString('no top-level paths:', implode("\n", $result['warnings']));

        $this->rrmdir($tmp);
    }

    public function testSyncInsertsNewPathLexicographicallyWhenTailIsFalse(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_tail_off_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));
        $this->writeEntitySchema($tmp, 'demo');

        $existing = <<<'YAML'
paths:
  /alpha:
    get:
      operationId: 'demo::alpha'
      summary: 'App\Controller\DemoController::alphaAction'
      responses:
        '200':
          description: OK
  /zeta:
    get:
      operationId: 'demo::zeta'
      summary: 'App\Controller\DemoController::zetaAction'
      responses:
        '200':
          description: OK
components:
  schemas:
    Placeholder:
      type: object

YAML;
        file_put_contents($tmp . '/controllers/demo.yml', $existing);

        $rows = [
            [
                'verb' => 'GET',
                'pattern' => '/alpha',
                'handler' => 'App\Controller\DemoController::alphaAction',
                'handlerId' => 'demo::alpha',
                'operationId' => 'demo::alpha',
            ],
            [
                'verb' => 'GET',
                'pattern' => '/middle',
                'handler' => 'App\Controller\DemoController::middleAction',
                'handlerId' => 'demo::middle',
                'operationId' => 'demo::middle',
            ],
            [
                'verb' => 'GET',
                'pattern' => '/zeta',
                'handler' => 'App\Controller\DemoController::zetaAction',
                'handlerId' => 'demo::zeta',
                'operationId' => 'demo::zeta',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            /** @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string}> $rows */
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '', false);
        $this->assertSame([], $result['warnings']);
        $this->assertNotSame([], $result['written']);
        $out = file_get_contents($tmp . '/controllers/demo.yml');
        $this->assertIsString($out);
        $pMiddle = strpos($out, '/middle:');
        $pZeta = strpos($out, '/zeta:');
        $this->assertNotFalse($pMiddle);
        $this->assertNotFalse($pZeta);
        $this->assertLessThan($pZeta, $pMiddle, 'non-tail insert should place /middle before /zeta');

        $this->rrmdir($tmp);
    }

    public function testSyncAppendsNewPathBeforeNextTopLevelKeyWhenTailIsTrue(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_tail_on_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));
        $this->writeEntitySchema($tmp, 'demo');

        $existing = <<<'YAML'
paths:
  /alpha:
    get:
      operationId: 'demo::alpha'
      summary: 'App\Controller\DemoController::alphaAction'
      responses:
        '200':
          description: OK
  /zeta:
    get:
      operationId: 'demo::zeta'
      summary: 'App\Controller\DemoController::zetaAction'
      responses:
        '200':
          description: OK
components:
  schemas:
    Placeholder:
      type: object

YAML;
        file_put_contents($tmp . '/controllers/demo.yml', $existing);

        $rows = [
            [
                'verb' => 'GET',
                'pattern' => '/alpha',
                'handler' => 'App\Controller\DemoController::alphaAction',
                'handlerId' => 'demo::alpha',
                'operationId' => 'demo::alpha',
            ],
            [
                'verb' => 'GET',
                'pattern' => '/middle',
                'handler' => 'App\Controller\DemoController::middleAction',
                'handlerId' => 'demo::middle',
                'operationId' => 'demo::middle',
            ],
            [
                'verb' => 'GET',
                'pattern' => '/zeta',
                'handler' => 'App\Controller\DemoController::zetaAction',
                'handlerId' => 'demo::zeta',
                'operationId' => 'demo::zeta',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            /** @param list<array{verb: string, pattern: string, handler: string, handlerId: string, operationId: string}> $rows */
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '', true);
        $this->assertSame([], $result['warnings']);
        $this->assertNotSame([], $result['written']);
        $out = file_get_contents($tmp . '/controllers/demo.yml');
        $this->assertIsString($out);
        $pMiddle = strpos($out, '/middle:');
        $pZeta = strpos($out, '/zeta:');
        $pComponents = strpos($out, 'components:');
        $this->assertNotFalse($pMiddle);
        $this->assertNotFalse($pZeta);
        $this->assertNotFalse($pComponents);
        $this->assertLessThan($pMiddle, $pZeta, 'tail insert should keep /zeta before /middle');
        $this->assertLessThan($pComponents, $pMiddle, 'tail insert should place /middle before top-level components');

        $this->rrmdir($tmp);
    }

    public function testSyncSkipsWildcardVerbRoutes(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_wild_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));

        $rows = [
            [
                'verb' => '*',
                'pattern' => '/anything',
                'handler' => 'App\Controller\GhostController::fallbackAction',
                'handlerId' => 'ghost::fallback',
                'operationId' => 'ghost::fallback',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, '', '');
        $this->assertSame([], $result['written']);
        $this->assertSame([], $result['warnings']);
        $this->assertFileDoesNotExist($tmp . '/controllers/Ghost__Controller__GhostController.yml');

        $this->rrmdir($tmp);
    }

    public function testSyncWithControllerFilterSkipsNonMatchingHandlers(): void
    {
        $tmp = sys_get_temp_dir() . '/openapi_yml_sync_filter_' . uniqid('', true);
        $this->assertTrue(mkdir($tmp . '/controllers', 0777, true));
        $this->assertTrue(mkdir($tmp . '/components', 0777, true));

        $rows = [
            [
                'verb' => 'GET',
                'pattern' => '/keep',
                'handler' => 'App\Controller\KeepController::indexAction',
                'handlerId' => 'keep::index',
                'operationId' => 'keep::index',
            ],
            [
                'verb' => 'GET',
                'pattern' => '/skip',
                'handler' => 'App\Controller\SkipController::indexAction',
                'handlerId' => 'skip::index',
                'operationId' => 'skip::index',
            ],
        ];

        $collector = new class ($rows) implements RouteCollectorInterface {
            public function __construct(protected array $rows)
            {
            }

            public function collect(): array
            {
                return $this->rows;
            }
        };

        $sync = new class ($collector) extends YmlSync {
            public function __construct(RouteCollectorInterface $collector)
            {
                $this->routeCollector = $collector;
                $fs = new class (new PathAlias()) extends Filesystem {
                    public function __construct(PathAlias $pathAlias)
                    {
                        $this->pathAlias = $pathAlias;
                    }
                };
                $this->filesystem = $fs;
                $this->yamlReader = new YamlReader();
            }
        };

        $result = $sync->sync($tmp, 'App\\Controller\\KeepController', '');
        $this->assertSame([], $result['warnings']);
        $written = $result['written'];
        $this->assertNotEmpty($written);
        $this->assertTrue(
            (bool)preg_grep('#/keep\\.yml$#', $written),
            'expected keep controller yaml to be written',
        );
        $this->assertSame(
            [],
            preg_grep('#/skip\\.yml$#', $written),
            'non-matching controller should not produce skip.yml',
        );

        $this->rrmdir($tmp);
    }

    protected function writeEntitySchema(string $root, string $id): void
    {
        $path = $root . '/components/entity.' . $id . '.yml';
        $yaml = "components:\n  schemas:\n    entity." . $id . ":\n      type: object\n";
        file_put_contents($path, $yaml);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
