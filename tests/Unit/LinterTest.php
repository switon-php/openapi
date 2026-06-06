<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\OpenApi\DocumentBuilderInterface;
use Switon\OpenApi\Linter;
use Switon\OpenApi\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class LinterTest extends TestCase
{
    public function testLintDetectsDuplicateOperationId(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => ['get' => ['operationId' => 'user::show']],
                '/b' => ['post' => ['operationId' => 'user::show']],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('Duplicate operationId: user::show', implode("\n", $result['errors']));
    }

    public function testLintWarnsOnExternalRef(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            '$ref' => 'https://example.com/schema.json',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'External/non-local $ref is not validated: https://example.com/schema.json',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintSkipsPathWhenOperationsValueIsNotArray(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/broken' => 'not-an-array',
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString('/broken', implode("\n", $result['warnings']));
    }

    public function testLintSkipsNonHttpOperationKeys(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'parameters' => [
                        ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']],
                    ],
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString('parameters', implode("\n", $result['warnings']));
    }

    public function testLintSkipsWhenHttpOperationValueIsNotArray(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => 'not-an-operation-object',
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function testLintSkipsWhenHttpVerbKeyUsesUppercase(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'GET' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function testLintWarnsWhenOperationIdMissing(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'Missing operationId at /a get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintDetectsBrokenLocalRef(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            '$ref' => '#/components/schemas/entity.user',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $joined = implode("\n", $result['errors']);
        $this->assertStringContainsString('Broken $ref: #/components/schemas/entity.user', $joined);
    }

    public function testLintWarnsWhenExpectedEntitySchemaMissing(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'order::list',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'response.simple' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'Missing schema entity.order expected by operationId order::list',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintWarnsWhenOperationIdFormatIsInvalid(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user-show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'Invalid operationId format at /a get: user-show',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintDoesNotWarnWhenOperationIdFormatIsValid(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString(
            'Invalid operationId format',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintWarnsWhenOperationResponsesMissing(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/users/{id}' => [
                    'get' => [
                        'operationId' => 'user::show',
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString('Missing responses at /users/{id} get', implode("\n", $result['warnings']));
        $this->assertStringContainsString('Missing 2xx success response at /users/{id} get', implode("\n", $result['warnings']));
    }

    public function testLintWarnsWhenPathTemplateParameterIsMissingInOperationParameters(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/users/{id}' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'Missing path parameter {id} at /users/{id} get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintDoesNotWarnWhenPathTemplateParameterMatchesOperationParameters(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/users/{id}' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString(
            'Missing path parameter {id} at /users/{id} get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintWarnsWhenPathTemplateParameterRequiredIsMissingInOperationParameters(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/users/{id}' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                        'parameters' => [
                            // Declared, but required is missing (OpenAPI requires path params to be required).
                            ['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'Path parameter {id} must set required: true at /users/{id} get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintDoesNotWarnWhenPathTemplateParameterRequiredIsTrue(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/users/{id}' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString(
            'Path parameter {id} must set required: true at /users/{id} get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintWarnsWhenOperationContainsUnexpectedPathParameter(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/users/{id}' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'tenantId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'Unexpected path parameter {tenantId} at /users/{id} get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintDoesNotWarnWhenAllPathParametersBelongToTemplate(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/users/{tenantId}/{id}' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                        'parameters' => [
                            ['name' => 'tenantId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString(
            'Unexpected path parameter {',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintWarnsWhenResponsesHaveNo2xxStatus(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            'default' => ['description' => 'fallback'],
                            '400' => ['description' => 'bad request'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'Missing 2xx success response at /a get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintDoesNotWarnWhenResponsesContain2xxStatus(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '2XX' => ['description' => 'success'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString(
            'Missing 2xx success response at /a get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintWarnsWhenResponseDescriptionMissing(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'Response 200 missing description at /a get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintDoesNotWarnWhenResponseDescriptionExists(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString(
            'Response 200 missing description at /a get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintWarnsWhenResponsesHasInvalidStatusKey(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                            'abc' => ['description' => 'bad key'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'Invalid responses status key abc at /a get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintDoesNotWarnWhenResponsesStatusKeyIsValid(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                            '4XX' => ['description' => 'client error'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString(
            'Invalid responses status key',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintWarnsWhenResponsesHaveNoPayloadSchemaOrReference(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                            '400' => ['description' => 'Bad Request'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString(
            'No response payload schema/reference at /a get',
            implode("\n", $result['warnings']),
        );
    }

    public function testLintDoesNotWarnWhenResponseContainsContentSchema(): void
    {
        $linter = $this->makeLinter([
            'paths' => [
                '/a' => [
                    'get' => [
                        'operationId' => 'user::show',
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'entity.user' => ['type' => 'object'],
                ],
            ],
        ]);

        $result = $linter->lint('/app/openapi');

        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString(
            'No response payload schema/reference at /a get',
            implode("\n", $result['warnings']),
        );
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function makeLinter(array $doc): Linter
    {
        $builder = $this->createMock(DocumentBuilderInterface::class);
        $builder->method('build')->willReturn($doc);

        return $this->make(Linter::class, [
            'documentBuilder' => $builder,
        ]);
    }
}
