<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use Switon\Core\FilesystemInterface;
use Switon\OpenApi\DocumentBuilder;
use Switon\Yaml\YamlReaderInterface;

/**
 * Test double with explicit constructor injection (no reflection).
 */
final class TestDocumentBuilder extends DocumentBuilder
{
    public function __construct(YamlReaderInterface $yamlReader, FilesystemInterface $filesystem)
    {
        $this->yamlReader = $yamlReader;
        $this->filesystem = $filesystem;
    }
}
