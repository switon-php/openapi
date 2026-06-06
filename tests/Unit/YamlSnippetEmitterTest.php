<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\OpenApi\YamlSnippetEmitter;

final class YamlSnippetEmitterTest extends TestCase
{
    public function testDumpQuotesKeysAndScalarsAndNestedSequences(): void
    {
        $yaml = YamlSnippetEmitter::dump([
            'plain' => 'ok',
            'empty' => '',
            'space' => ' leading',
            'dash' => '-dash',
            'newline' => "a\nb",
            'colon' => 'a: b',
            'hash' => 'a#b',
            'quote' => "a'b",
            'bool' => true,
            'null' => null,
            'int' => 1,
            'float' => 1.5,
            'numeric key' => [
                1 => 'one',
                'list' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
                'nested list' => [
                    ['x' => ['y' => 'z']],
                ],
            ],
        ]);

        $this->assertStringContainsString("plain: ok", $yaml);
        $this->assertStringContainsString("empty: ''", $yaml);
        $this->assertStringContainsString("space: ' leading'", $yaml);
        $this->assertStringContainsString("dash: '-dash'", $yaml);
        $this->assertStringContainsString("newline: 'a\nb'", $yaml);
        $this->assertStringContainsString("colon: 'a: b'", $yaml);
        $this->assertStringContainsString("hash: 'a#b'", $yaml);
        $this->assertStringContainsString("quote: 'a''b'", $yaml);
        $this->assertStringContainsString("bool: true", $yaml);
        $this->assertStringContainsString("null: null", $yaml);
        $this->assertStringContainsString("int: 1", $yaml);
        $this->assertStringContainsString("float: 1.5", $yaml);
        $this->assertStringContainsString("'numeric key':", $yaml);
        $this->assertStringContainsString("'1': one", $yaml);
        $this->assertStringContainsString("-\n", $yaml);
        $this->assertStringContainsString("id: 1", $yaml);
        $this->assertStringContainsString("x:", $yaml);
    }
}
