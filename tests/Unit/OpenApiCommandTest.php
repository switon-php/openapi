<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\OpenApi\Command\OpenApiCommand;
use Switon\Testing\Container;
use Switon\OpenApi\Tests\TestCase;

final class OpenApiCommandTest extends TestCase
{
    public function testInitActionReadsScaffoldFromResourceAlias(): void
    {
        $targetRoot = sys_get_temp_dir() . '/openapi_init_' . uniqid('', true);
        $pathAlias = (new Container())->get(PathAliasInterface::class);
        $pathAlias->set('@root', $targetRoot);

        $filesystem = $this->createStub(FilesystemInterface::class);
        $console = $this->createMock(ConsoleInterface::class);

        $filesystem->method('exists')->willReturnCallback(
            static function (string $path) use ($targetRoot): bool {
                if ($path === $targetRoot . '/openapi/openapi.yml') {
                    return false;
                }

                return is_file($path) || is_dir($path);
            }
        );
        $filesystem->method('isDir')->willReturnCallback(static fn (string $path): bool => is_dir($path));
        $filesystem->method('mkdir')->willReturnCallback(static function (string $path): void {
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        });
        $filesystem->method('read')->willReturnCallback(static fn (string $path): string => (string)file_get_contents($path));
        $filesystem->method('write')->willReturnCallback(static function (string $path, string $content): void {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, $content);
        });

        $console->expects($this->once())
            ->method('writeLn')
            ->with($targetRoot . '/openapi');

        $command = $this->make(OpenApiCommand::class, [
            'console' => $console,
            'filesystem' => $filesystem,
            'pathAlias' => $pathAlias,
        ]);

        $code = $command->initAction('@root/openapi', true);

        $this->assertSame(0, $code);
        $this->assertFileExists($targetRoot . '/openapi/openapi.yml');
        $this->assertFileExists($targetRoot . '/openapi/README.md');
        $this->assertFileExists($targetRoot . '/openapi/recommended.example.yml');
        $this->assertFileExists($targetRoot . '/openapi/components/entity.example.yml');

        $this->removeDir($targetRoot);
    }

    public function testInitActionReturnsErrorWhenOpenApiAlreadyExists(): void
    {
        $targetRoot = sys_get_temp_dir() . '/openapi_init_dup_' . uniqid('', true);
        $pathAlias = (new Container())->get(PathAliasInterface::class);
        $pathAlias->set('@root', $targetRoot);

        $openapiDir = $targetRoot . '/openapi';
        mkdir($openapiDir, 0777, true);
        touch($openapiDir . '/openapi.yml');

        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => is_file($path) || is_dir($path)
        );

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with(
                'Already initialized (openapi.yml exists): {path}',
                $this->callback(fn (array $ctx): bool => ($ctx['path'] ?? '') === $openapiDir . '/openapi.yml'),
                1,
            )
            ->willReturn(1);

        $command = $this->make(OpenApiCommand::class, [
            'console' => $console,
            'filesystem' => $filesystem,
            'pathAlias' => $pathAlias,
        ]);

        $this->assertSame(1, $command->initAction('@root/openapi', true));

        $this->removeDir($targetRoot);
    }

    protected function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
