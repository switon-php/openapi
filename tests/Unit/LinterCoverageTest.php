<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\OpenApi\DocumentBuilderInterface;
use Switon\OpenApi\Linter;

final class LinterCoverageTest extends TestCase
{
    public function testHelperMethodsCoverSchemaAndParameterEdgeCases(): void
    {
        $probe = $this->makeProbe([]);

        $this->assertSame([], $probe->schemaMapProbe(['components' => null]));
        $this->assertSame([], $probe->schemaMapProbe(['components' => ['schemas' => null]]));
        $this->assertSame('entity.order', $probe->expectedEntityFromOperationIdProbe('order::show'));
        $this->assertNull($probe->expectedEntityFromOperationIdProbe(''));
        $this->assertTrue($probe->isValidOperationIdProbe('order::show'));
        $this->assertFalse($probe->isValidOperationIdProbe('order-show'));
        $this->assertTrue($probe->hasNonEmptyResponsesProbe(['responses' => ['200' => ['description' => 'OK']]]));
        $this->assertFalse($probe->hasNonEmptyResponsesProbe([]));
        $this->assertTrue($probe->hasSuccessResponseProbe(['responses' => [201 => ['description' => 'Created']]]));
        $this->assertTrue($probe->hasSuccessResponseProbe(['responses' => ['2XX' => ['description' => 'OK']]]));
        $this->assertTrue($probe->hasSuccessResponseProbe(['responses' => ['200' => ['description' => 'OK']]]));
        $this->assertFalse($probe->hasSuccessResponseProbe(['responses' => ['404' => ['description' => 'Nope']]]));
        $this->assertSame(['200', 'default'], $probe->responsesMissingDescriptionProbe([
            'responses' => [
                '200' => [],
                'default' => ['description' => ''],
            ],
        ]));
        $this->assertSame(['foo', '700'], $probe->responsesHasInvalidStatusKeysProbe([
            'responses' => [
                'foo' => ['description' => 'bad'],
                '700' => ['description' => 'bad'],
            ],
        ]));
        $this->assertTrue($probe->hasAnyResponsePayloadHintProbe([
            'responses' => [
                '200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            ],
        ]));
        $this->assertFalse($probe->hasAnyResponsePayloadHintProbe(['responses' => ['200' => ['description' => 'OK']]]));
        $this->assertSame(['id'], $probe->missingPathParametersProbe('/users/{id}', [
            'parameters' => [
                ['name' => 'page', 'in' => 'query'],
            ],
        ]));
        $this->assertSame(['id'], $probe->pathParametersMustBeRequiredProbe('/users/{id}', [
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => false],
                ['name' => 'extra', 'in' => 'path', 'required' => true],
            ],
        ]));
        $this->assertSame(['extra'], $probe->unexpectedPathParametersProbe('/users/{id}', [
            'parameters' => [
                ['name' => 'extra', 'in' => 'path', 'required' => true],
            ],
        ]));
        $this->assertSame(['#/components/schemas/User', 'https://example.com/schema.json'], $probe->collectRefsProbe([
            'items' => [
                '$ref' => '#/components/schemas/User',
                'nested' => [
                    '$ref' => 'https://example.com/schema.json',
                ],
            ],
        ]));
        $this->assertTrue($probe->jsonPointerExistsProbe([
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ], 'components/schemas/entity.user'));
        $this->assertFalse($probe->jsonPointerExistsProbe(['components' => []], 'components/schemas/entity.user'));
    }

    public function testLintReportsCommonOpenApiWarningsAndErrors(): void
    {
        $probe = $this->makeProbe([
            'paths' => [
                '/users/{id}' => [
                    'get' => [
                        'operationId' => 'missing::show',
                        'responses' => [
                            201 => ['description' => 'Created'],
                            '2XX' => ['description' => 'OK'],
                            'bad' => ['description' => 'Bad'],
                        ],
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query'],
                        ],
                    ],
                    'post' => [
                        'operationId' => 'missing-show',
                        'parameters' => [
                            ['name' => 'extra', 'in' => 'path', 'required' => false],
                        ],
                    ],
                ],
                '/dup' => [
                    'get' => [
                        'operationId' => 'missing::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                ],
                '/ref' => [
                    'get' => [
                        'operationId' => 'ref::show',
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            '$ref' => '#/components/schemas/missing',
                                        ],
                                    ],
                                ],
                            ],
                            'default' => [
                                '$ref' => 'https://example.com/responses/ok',
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.ref' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $probe->lint('/app/openapi');
        $joinedWarnings = implode("\n", $result['warnings']);
        $joinedErrors = implode("\n", $result['errors']);

        $this->assertStringContainsString('Missing schema entity.missing expected by operationId missing::show', $joinedWarnings);
        $this->assertStringContainsString('Missing path parameter {id} at /users/{id} get', $joinedWarnings);
        $this->assertStringContainsString('Invalid operationId format at /users/{id} post: missing-show', $joinedWarnings);
        $this->assertStringContainsString('Missing schema entity.missing-show expected by operationId missing-show', $joinedWarnings);
        $this->assertStringContainsString('Missing responses at /users/{id} post', $joinedWarnings);
        $this->assertStringContainsString('Missing 2xx success response at /users/{id} post', $joinedWarnings);
        $this->assertStringContainsString('No response payload schema/reference at /users/{id} post', $joinedWarnings);
        $this->assertStringContainsString('Unexpected path parameter {extra} at /users/{id} post', $joinedWarnings);
        $this->assertStringContainsString('Missing path parameter {id} at /users/{id} get', $joinedWarnings);
        $this->assertStringContainsString('Invalid responses status key bad at /users/{id} get', $joinedWarnings);
        $this->assertStringContainsString('External/non-local $ref is not validated: https://example.com/responses/ok', $joinedWarnings);
        $this->assertStringContainsString('Duplicate operationId: missing::show', $joinedErrors);
        $this->assertStringContainsString('Broken $ref: #/components/schemas/missing', $joinedErrors);
    }

    private function makeProbe(array $doc): object
    {
        return new class ($doc) extends Linter {
            public function __construct(array $doc)
            {
                $this->documentBuilder = new class ($doc) implements DocumentBuilderInterface {
                    public function __construct(private array $doc)
                    {
                    }

                    public function build(string $root, bool $deref = false, bool $keepComponents = false): array
                    {
                        return $this->doc;
                    }
                };
            }

            public function schemaMapProbe(array $doc): array
            {
                return $this->schemaMap($doc);
            }

            public function expectedEntityFromOperationIdProbe(string $operationId): ?string
            {
                return $this->expectedEntityFromOperationId($operationId);
            }

            public function isValidOperationIdProbe(string $operationId): bool
            {
                return $this->isValidOperationId($operationId);
            }

            public function hasNonEmptyResponsesProbe(array $operation): bool
            {
                return $this->hasNonEmptyResponses($operation);
            }

            public function hasSuccessResponseProbe(array $operation): bool
            {
                return $this->hasSuccessResponse($operation);
            }

            public function responsesMissingDescriptionProbe(array $operation): array
            {
                return $this->responsesMissingDescription($operation);
            }

            public function responsesHasInvalidStatusKeysProbe(array $operation): array
            {
                return $this->responsesHasInvalidStatusKeys($operation);
            }

            public function hasAnyResponsePayloadHintProbe(array $operation): bool
            {
                return $this->hasAnyResponsePayloadHint($operation);
            }

            public function missingPathParametersProbe(string $path, array $operation): array
            {
                return $this->missingPathParameters($path, $operation);
            }

            public function pathParametersMustBeRequiredProbe(string $path, array $operation): array
            {
                return $this->pathParametersMustBeRequired($path, $operation);
            }

            public function unexpectedPathParametersProbe(string $path, array $operation): array
            {
                return $this->unexpectedPathParameters($path, $operation);
            }

            public function collectRefsProbe(mixed $node): array
            {
                return $this->collectRefs($node);
            }

            public function jsonPointerExistsProbe(array $doc, string $pointer): bool
            {
                return $this->jsonPointerExists($doc, $pointer);
            }
        };
    }
}
