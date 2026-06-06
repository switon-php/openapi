<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\OpenApi\YmlSync;

final class YmlSyncCoverageTest extends TestCase
{
    public function testTextHelpersCoverQuotingIndentAndVerbMapping(): void
    {
        $probe = $this->makeProbe();

        $this->assertSame(2, $probe->leadingSpacesLenProbe('  paths:'));
        $this->assertNull($probe->leadingSpacesLenProbe("\tpaths:"));
        $this->assertTrue($probe->isTopLevelMappingKeyLineProbe('components:'));
        $this->assertFalse($probe->isTopLevelMappingKeyLineProbe('  /foo:'));
        $this->assertSame('', $probe->unquoteYamlScalarKeyProbe(''));
        $this->assertSame('plain', $probe->unquoteYamlScalarKeyProbe('plain'));
        $this->assertSame("a'b", $probe->unquoteYamlScalarKeyProbe("'a''b'"));
        $this->assertSame('value', $probe->unquoteYamlScalarKeyProbe('"value"'));
        $this->assertSame(['id', 'slug'], $probe->pathParameterNamesFromPatternProbe('/users/{id}/{id}/{slug}'));
        $this->assertSame('get', $probe->openApiMethodKeyProbe('GET'));
        $this->assertSame('patch', $probe->openApiMethodKeyProbe('patch'));
        $this->assertNull($probe->openApiMethodKeyProbe('FETCH'));
        $this->assertSame('/safe/path', $probe->yamlPathKeyProbe('/safe/path'));
        $this->assertSame("'needs space'", $probe->yamlPathKeyProbe('needs space'));
    }

    public function testBoundaryHelpersCoverPathScanningAndInsertionOrdering(): void
    {
        $probe = $this->makeProbe();
        $lines = [
            '# comment',
            'paths:',
            '  /beta:',
            '    get:',
            '  /alpha:',
            '    get:',
            'components:',
            '  schemas:',
        ];

        $pathsLine = $probe->findPathsLineIndexProbe($lines);
        $this->assertSame(1, $pathsLine);
        $this->assertSame(4, $probe->findPathLineIndexProbe($lines, $pathsLine, '/alpha'));
        $this->assertSame(4, $probe->findNextPathOrTopLevelBoundaryProbe($lines, 2));
        $this->assertSame(6, $probe->findFirstTopLevelKeyAfterPathsProbe($lines, $pathsLine));
        $this->assertSame(2, $probe->findInsertIndexForNewPathProbe($lines, $pathsLine, '/aardvark'));
        $this->assertSame(6, $probe->findInsertIndexForNewPathProbe($lines, $pathsLine, '/zeta'));
        $this->assertSame([
            ['line' => 2, 'path' => '/beta'],
            ['line' => 4, 'path' => '/alpha'],
        ], $probe->collectOpenApiPathEntriesProbe($lines, $pathsLine, 6));
    }

    public function testBuildHelpersEmitExpectedYamlBlocks(): void
    {
        $probe = $this->makeProbe();
        $routes = [
            [
                'verb' => 'GET',
                'pattern' => '/users/{id}',
                'handler' => 'App\\Controller\\UserController::showAction',
                'handlerId' => 'user::show',
                'operationId' => 'user::show',
            ],
            [
                'verb' => 'POST',
                'pattern' => '/users/{id}',
                'handler' => 'App\\Controller\\UserController::saveAction',
                'handlerId' => 'user::save',
                'operationId' => 'user::save',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];

        $this->assertSame('user.yml', $probe->controllerYamlFileNameProbe($routes));
        $this->assertSame('user', $probe->handlerIdPrefixFromRoutesProbe($routes));
        $this->assertSame('#/components/schemas/entity.user', $probe->entitySchemaRefFromOperationIdProbe('user::show'));
        $this->assertSame('#/components/schemas/entity.show', $probe->entitySchemaRefFromOperationIdProbe('show'));
        $this->assertSame('/tmp/components/entity.user.yml', $probe->entitySchemaFilePathProbe('/tmp/components', 'user'));
        $this->assertSame('/tmp/components/entity.unknown.yml', $probe->entitySchemaFilePathProbe('/tmp/components', ''));
        $this->assertStringContainsString('entity.user', $probe->buildEntitySchemaYmlProbe('entity.user'));

        $stubLines = $probe->buildPathParameterStubLinesProbe(['id', 'slug']);
        $this->assertSame('      parameters:', $stubLines[0]);
        $this->assertStringContainsString("name: 'id'", implode("\n", $stubLines));
        $this->assertSame([], $probe->buildPathParameterStubLinesProbe([]));

        $nullResponse = $probe->buildResponseStubLinesProbe(null, '#/components/schemas/entity.user');
        $this->assertStringContainsString('Recommended entity model', implode("\n", $nullResponse));
        $this->assertStringContainsString("# \$ref: '#/components/schemas/entity.user'", implode("\n", $nullResponse));

        $schemaResponse = $probe->buildResponseStubLinesProbe([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
        ], '#/components/schemas/entity.user');
        $this->assertStringContainsString('content:', implode("\n", $schemaResponse));
        $this->assertStringContainsString('application/json:', implode("\n", $schemaResponse));
        $this->assertStringContainsString('type: object', implode("\n", $schemaResponse));

        $controllerYml = $probe->buildControllerYmlProbe($routes);
        $this->assertStringContainsString('paths:', $controllerYml);
        $this->assertStringContainsString('/users/{id}:', $controllerYml);
        $this->assertStringContainsString('operationId: \'user::save\'', $controllerYml);
    }

    private function makeProbe(): object
    {
        return new class () extends YmlSync {
            public function leadingSpacesLenProbe(string $line): ?int
            {
                return $this->leadingSpacesLen($line);
            }

            public function isTopLevelMappingKeyLineProbe(string $line): bool
            {
                return $this->isTopLevelMappingKeyLine($line);
            }

            public function unquoteYamlScalarKeyProbe(string $raw): string
            {
                return $this->unquoteYamlScalarKey($raw);
            }

            public function pathParameterNamesFromPatternProbe(string $pattern): array
            {
                return $this->pathParameterNamesFromPattern($pattern);
            }

            public function openApiMethodKeyProbe(string $verb): ?string
            {
                return $this->openApiMethodKey($verb);
            }

            public function yamlPathKeyProbe(string $path): string
            {
                return $this->yamlPathKey($path);
            }

            public function findPathsLineIndexProbe(array $lines): ?int
            {
                return $this->findPathsLineIndex($lines);
            }

            public function findPathLineIndexProbe(array $lines, int $pathsLineIdx, string $pathPattern): ?int
            {
                return $this->findPathLineIndex($lines, $pathsLineIdx, $pathPattern);
            }

            public function findNextPathOrTopLevelBoundaryProbe(array $lines, int $pathLineIdx): ?int
            {
                return $this->findNextPathOrTopLevelBoundary($lines, $pathLineIdx);
            }

            public function findFirstTopLevelKeyAfterPathsProbe(array $lines, int $pathsLineIdx): int
            {
                return $this->findFirstTopLevelKeyAfterPaths($lines, $pathsLineIdx);
            }

            public function findInsertIndexForNewPathProbe(array $lines, int $pathsLineIdx, string $pathPattern): int
            {
                return $this->findInsertIndexForNewPath($lines, $pathsLineIdx, $pathPattern);
            }

            public function collectOpenApiPathEntriesProbe(array $lines, int $pathsLineIdx, int $endExclusive): array
            {
                return $this->collectOpenApiPathEntries($lines, $pathsLineIdx, $endExclusive);
            }

            public function controllerYamlFileNameProbe(array $routes): string
            {
                return $this->controllerYamlFileName($routes);
            }

            public function handlerIdPrefixFromRoutesProbe(array $routes): string
            {
                return $this->handlerIdPrefixFromRoutes($routes);
            }

            public function entitySchemaRefFromOperationIdProbe(string $operationId): ?string
            {
                return $this->entitySchemaRefFromOperationId($operationId);
            }

            public function entitySchemaFilePathProbe(string $componentsDir, string $controllerId): string
            {
                return $this->entitySchemaFilePath($componentsDir, $controllerId);
            }

            public function buildEntitySchemaYmlProbe(string $schemaName): string
            {
                return $this->buildEntitySchemaYml($schemaName);
            }

            public function buildPathParameterStubLinesProbe(array $paramNames): array
            {
                return $this->buildPathParameterStubLines($paramNames);
            }

            public function buildResponseStubLinesProbe(?array $responseSchema, ?string $entitySchemaRef = null): array
            {
                return $this->buildResponseStubLines($responseSchema, $entitySchemaRef);
            }

            public function buildControllerYmlProbe(array $routes): string
            {
                return $this->buildControllerYml($routes);
            }
        };
    }
}
