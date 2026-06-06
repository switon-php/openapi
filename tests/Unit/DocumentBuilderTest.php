<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Core\FilesystemInterface;
use Switon\OpenApi\DocumentBuilder;
use Switon\Yaml\YamlReader;

#[AllowMockObjectsWithoutExpectations]
final class DocumentBuilderTest extends TestCase
{
    private const ROOT = '/app/openapi';

    public function testMergeUsesSkeletonWhenNoMainFile(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: false,
            controllerFiles: [
                self::ROOT . '/controllers/user.yml' => <<<'YAML'
paths:
  /users:
    get:
      summary: list
YAML,
            ],
        ));

        $doc = $builder->build(self::ROOT);

        $this->assertSame('3.0.3', $doc['openapi']);
        $this->assertArrayHasKey('/users', $doc['paths']);
        $this->assertSame('list', $doc['paths']['/users']['get']['summary']);
    }

    public function testMergeMainOpenapiYmlWithControllers(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: true,
            mainYaml: <<<'YAML'
openapi: 3.1.0
info:
  title: Demo
  version: '2.0'
YAML,
            controllerFiles: [
                self::ROOT . '/controllers/a.yml' => <<<'YAML'
paths:
  /x:
    post:
      summary: create
YAML,
            ],
        ));

        $doc = $builder->build(self::ROOT);

        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame('Demo', $doc['info']['title']);
        $this->assertSame('create', $doc['paths']['/x']['post']['summary']);
    }

    public function testIgnoresOpenapiYamlWhenOpenapiYmlAbsent(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: false,
            legacyYamlExists: true,
            controllerFiles: [
                self::ROOT . '/controllers/a.yml' => <<<'YAML'
paths:
  /x:
    get:
      summary: from-controller
YAML,
            ],
            mainYaml: <<<'YAML'
openapi: 3.1.0
info:
  title: MustNotBeUsed
  version: '1'
YAML,
        ));

        $doc = $builder->build(self::ROOT);

        $this->assertSame('3.0.3', $doc['openapi']);
        $this->assertSame('API', $doc['info']['title']);
        $this->assertSame('from-controller', $doc['paths']['/x']['get']['summary']);
    }

    public function testLaterControllerOverridesSamePathMethod(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: false,
            controllerFiles: [
                self::ROOT . '/controllers/a.yml' => <<<'YAML'
paths:
  /r:
    get:
      summary: first
YAML,
                self::ROOT . '/controllers/b.yml' => <<<'YAML'
paths:
  /r:
    get:
      summary: second
YAML,
            ],
        ));

        $doc = $builder->build(self::ROOT);

        $this->assertSame('second', $doc['paths']['/r']['get']['summary']);
    }

    public function testMergeComponentsSchemas(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: false,
            componentFiles: [
                self::ROOT . '/components/a.yml' => <<<'YAML'
components:
  schemas:
    A:
      type: object
YAML,
                self::ROOT . '/components/b.yml' => <<<'YAML'
components:
  schemas:
    B:
      type: string
YAML,
            ],
        ));

        $doc = $builder->build(self::ROOT);

        $this->assertArrayHasKey('A', $doc['components']['schemas']);
        $this->assertArrayHasKey('B', $doc['components']['schemas']);
    }

    public function testMergeMainFragmentOverridesScalarsAndMergesArrays(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: true,
            mainYaml: <<<'YAML'
openapi: 3.1.0
info:
  title: Demo
paths:
  /alpha:
    get:
      summary: main
components:
  schemas:
    User:
      type: object
servers:
  - url: https://api.example.test
YAML,
            controllerFiles: [
                self::ROOT . '/controllers/a.yml' => <<<'YAML'
paths:
  /alpha:
    post:
      summary: controller
  /beta:
    get:
      summary: beta
components:
  schemas:
    Order:
      type: object
security:
  - bearer: []
YAML,
            ],
        ));

        $doc = $builder->build(self::ROOT);

        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame('Demo', $doc['info']['title']);
        $this->assertSame('main', $doc['paths']['/alpha']['get']['summary']);
        $this->assertSame('controller', $doc['paths']['/alpha']['post']['summary']);
        $this->assertSame('beta', $doc['paths']['/beta']['get']['summary']);
        $this->assertSame('User', array_key_first($doc['components']['schemas']));
        $this->assertArrayHasKey('Order', $doc['components']['schemas']);
        $this->assertSame(['url: https://api.example.test'], $doc['servers']);
        // Switon YamlReader parses `- bearer: []` as a single sequence scalar (see YamlReader tests).
        $this->assertSame(['bearer: []'], $doc['security']);
    }

    public function testBuildCanDereferenceAndKeepComponents(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: false,
            controllerFiles: [
                self::ROOT . '/controllers/user.yml' => <<<'YAML'
paths:
  /users:
    get:
      responses:
        '200':
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/User'
YAML,
            ],
            componentFiles: [
                self::ROOT . '/components/user.yml' => <<<'YAML'
components:
  schemas:
    User:
      type: object
      properties:
        id:
          type: integer
YAML,
            ],
        ));

        $doc = $builder->build(self::ROOT, deref: true, keepComponents: true);

        $this->assertArrayHasKey('components', $doc);
        $responseKey = array_key_first($doc['paths']['/users']['get']['responses']);
        $this->assertSame("'200'", $responseKey);
        $this->assertSame('object', $doc['paths']['/users']['get']['responses'][$responseKey]['content']['application/json']['schema']['type']);
    }

    public function testBuildCanDereferenceSiblingSiblingsAndEmptyRefTarget(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: true,
            mainYaml: <<<'YAML'
paths:
  /x:
    get:
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/User'
                nullable: true
components:
  schemas:
    User:
      type: object
      properties:
        id:
          type: integer
    Empty:
      $ref: '#/components/schemas/User'
YAML,
        ));

        $resolved = $builder->build(self::ROOT, deref: true, keepComponents: true);

        $responseKey = array_key_first($resolved['paths']['/x']['get']['responses']);
        $schema = $resolved['paths']['/x']['get']['responses'][$responseKey]['content']['application/json']['schema'] ?? null;
        $this->assertIsArray($schema);
        $this->assertTrue($schema['nullable'] ?? false);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertSame(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], $resolved['components']['schemas']['Empty']);
    }

    public function testBuildCanDereferenceLocalRefs(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: true,
            mainYaml: <<<'YAML'
components:
  schemas:
    entity.user:
      type: object
      properties:
        id:
          type: integer
paths:
  /ok:
    get:
      responses:
        '200':
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/entity.user'
YAML,
        ));
        $resolved = $builder->build(self::ROOT, deref: true, keepComponents: true);

        $responseKey = array_key_first($resolved['paths']['/ok']['get']['responses']);
        $schema = $resolved['paths']['/ok']['get']['responses'][$responseKey]['content']['application/json']['schema'] ?? null;
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
    }

    public function testBuildSkipsMissingFragmentDirectories(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: false,
            controllersExist: false,
            componentsExist: false,
        ));

        $doc = $builder->build(self::ROOT);

        $this->assertSame('3.0.3', $doc['openapi']);
        $this->assertSame([], $doc['paths']);
        $this->assertSame([], $doc['components']);
    }

    public function testBuildCanDereferenceBrokenPointer(): void
    {
        $this->expectException(\Switon\Core\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Broken OpenAPI local $ref pointer');

        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: true,
            mainYaml: <<<'YAML'
paths:
  /x:
    get:
      responses:
        '200':
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Missing'
YAML,
        ));

        $builder->build(self::ROOT, deref: true);
    }

    public function testBuildCanDetectDereferenceCycles(): void
    {
        $this->expectException(\Switon\Core\Exception\RuntimeException::class);
        $this->expectExceptionMessage('OpenAPI $ref cycle detected');

        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: true,
            mainYaml: <<<'YAML'
components:
  schemas:
    User:
      $ref: '#/components/schemas/User'
YAML,
        ));

        $builder->build(self::ROOT, deref: true);
    }

    public function testBuildWithDerefRemovesComponentsWhenKeepComponentsIsFalse(): void
    {
        $builder = $this->makeBuilder($this->makeFilesystem(
            mainExists: false,
            controllerFiles: [
                self::ROOT . '/controllers/user.yml' => <<<'YAML'
paths:
  /users:
    get:
      responses:
        '200':
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/User'
YAML,
            ],
            componentFiles: [
                self::ROOT . '/components/user.yml' => <<<'YAML'
components:
  schemas:
    User:
      type: object
      properties:
        id:
          type: integer
YAML,
            ],
        ));

        $doc = $builder->build(self::ROOT, deref: true, keepComponents: false);

        $this->assertArrayHasKey('/users', $doc['paths']);
        $this->assertSame('get', array_key_first($doc['paths']['/users']));
        $this->assertArrayNotHasKey('components', $doc);
    }

    private function makeBuilder(FilesystemInterface|MockObject $filesystem): DocumentBuilder
    {
        return new TestDocumentBuilder(new YamlReader(), $filesystem);
    }

    /**
     * @param array<string, string> $controllerFiles path => YAML source
     * @param array<string, string> $componentFiles path => YAML source
     */
    private function makeFilesystem(
        bool   $mainExists,
        array  $controllerFiles = [],
        array  $componentFiles = [],
        string $mainYaml = '',
        bool   $legacyYamlExists = false,
        bool   $controllersExist = true,
        bool   $componentsExist = true,
    ): FilesystemInterface|MockObject {
        $fs = $this->createMock(FilesystemInterface::class);

        $controllerPaths = array_keys($controllerFiles);
        sort($controllerPaths);
        $componentPaths = array_keys($componentFiles);
        sort($componentPaths);

        $fs->method('exists')->willReturnCallback(function (string $p) use ($mainExists, $legacyYamlExists, $controllersExist, $componentsExist): bool {
            if ($p === self::ROOT . '/openapi.yml') {
                return $mainExists;
            }
            if ($p === self::ROOT . '/openapi.yaml') {
                return $legacyYamlExists;
            }
            if ($p === self::ROOT . '/controllers') {
                return $controllersExist;
            }
            if ($p === self::ROOT . '/components') {
                return $componentsExist;
            }

            return false;
        });

        $fs->method('isDir')->willReturnCallback(
            fn (string $p): bool => (
                ($p === self::ROOT . '/controllers' && $controllersExist)
                || ($p === self::ROOT . '/components' && $componentsExist)
            ),
        );

        $fs->method('glob')->willReturnCallback(function (string $pattern) use ($controllerPaths, $componentPaths): array {
            if (!str_ends_with($pattern, '*.yml')) {
                return [];
            }
            if (str_starts_with($pattern, self::ROOT . '/controllers/')) {
                return $controllerPaths;
            }
            if (str_starts_with($pattern, self::ROOT . '/components/')) {
                return $componentPaths;
            }

            return [];
        });

        $fs->method('read')->willReturnCallback(function (string $p) use ($mainYaml, $controllerFiles, $componentFiles): string {
            if ($p === self::ROOT . '/openapi.yml') {
                return $mainYaml;
            }
            if ($p === self::ROOT . '/openapi.yaml') {
                $this->fail('openapi.yaml must not be read (only .yml is supported): ' . $p);
            }
            if (isset($controllerFiles[$p])) {
                return $controllerFiles[$p];
            }
            if (isset($componentFiles[$p])) {
                return $componentFiles[$p];
            }
            $this->fail('Unexpected read: ' . $p);
        });

        return $fs;
    }
}
