<?php

declare(strict_types=1);

namespace Switon\OpenApi;

use Switon\Core\Attribute\Autowired;

use function array_key_exists;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function preg_match_all;
use function sort;
use function str_starts_with;
use function substr;

/**
 * Default {@see LinterInterface}.
 *
 * Checks duplicate <code>operationId</code>, broken local <code>$ref</code> pointers, and missing
 * <code>entity.{handler-id-prefix}</code> schemas expected by operation ids.
 */
class Linter implements LinterInterface
{
    #[Autowired] protected DocumentBuilderInterface $documentBuilder;

    public function lint(string $root): array
    {
        $errors = [];
        $warnings = [];
        $doc = $this->documentBuilder->build($root);

        $schemas = $this->schemaMap($doc);
        $ids = [];
        $dupIds = [];
        $paths = $doc['paths'] ?? [];
        if (is_array($paths)) {
            foreach ($paths as $path => $operations) {
                if (!is_array($operations)) {
                    continue;
                }
                foreach ($operations as $method => $operation) {
                    if (!is_array($operation)) {
                        continue;
                    }
                    if (!in_array((string)$method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'], true)) {
                        continue;
                    }
                    $operationId = $operation['operationId'] ?? null;
                    if (!is_string($operationId) || $operationId === '') {
                        $warnings[] = 'Missing operationId at ' . $path . ' ' . $method;
                        continue;
                    }
                    if (!$this->isValidOperationId($operationId)) {
                        $warnings[] = 'Invalid operationId format at ' . $path . ' ' . $method . ': ' . $operationId;
                    }
                    if (isset($ids[$operationId])) {
                        $dupIds[$operationId] = true;
                    } else {
                        $ids[$operationId] = true;
                    }

                    $expectedEntity = $this->expectedEntityFromOperationId($operationId);
                    if ($expectedEntity !== null && !array_key_exists($expectedEntity, $schemas)) {
                        $warnings[] = 'Missing schema ' . $expectedEntity . ' expected by operationId ' . $operationId;
                    }

                    if (!$this->hasNonEmptyResponses($operation)) {
                        $warnings[] = 'Missing responses at ' . $path . ' ' . $method;
                    }
                    if (!$this->hasSuccessResponse($operation)) {
                        $warnings[] = 'Missing 2xx success response at ' . $path . ' ' . $method;
                    }
                    foreach ($this->responsesMissingDescription($operation) as $statusCode) {
                        $warnings[] = 'Response ' . $statusCode . ' missing description at ' . $path . ' ' . $method;
                    }
                    foreach ($this->responsesHasInvalidStatusKeys($operation) as $invalidKey) {
                        $warnings[] = 'Invalid responses status key ' . $invalidKey . ' at ' . $path . ' ' . $method;
                    }
                    if (!$this->hasAnyResponsePayloadHint($operation)) {
                        $warnings[] = 'No response payload schema/reference at ' . $path . ' ' . $method;
                    }

                    foreach ($this->missingPathParameters($path, $operation) as $missingParam) {
                        $warnings[] = 'Missing path parameter {' . $missingParam . '} at ' . $path . ' ' . $method;
                    }

                    foreach ($this->pathParametersMustBeRequired($path, $operation) as $requiredParam) {
                        $warnings[] = 'Path parameter {' . $requiredParam . '} must set required: true at ' . $path . ' ' . $method;
                    }

                    foreach ($this->unexpectedPathParameters($path, $operation) as $unexpectedParam) {
                        $warnings[] = 'Unexpected path parameter {' . $unexpectedParam . '} at ' . $path . ' ' . $method;
                    }
                }
            }
        }
        if ($dupIds !== []) {
            $keys = array_keys($dupIds);
            sort($keys);
            foreach ($keys as $dup) {
                $errors[] = 'Duplicate operationId: ' . $dup;
            }
        }

        foreach ($this->collectRefs($doc) as $ref) {
            if (!str_starts_with($ref, '#/')) {
                $warnings[] = 'External/non-local $ref is not validated: ' . $ref;
                continue;
            }
            if (!$this->jsonPointerExists($doc, substr($ref, 2))) {
                $errors[] = 'Broken $ref: ' . $ref;
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    protected function schemaMap(array $doc): array
    {
        $components = $doc['components'] ?? null;
        if (!is_array($components)) {
            return [];
        }
        $schemas = $components['schemas'] ?? null;
        if (!is_array($schemas)) {
            return [];
        }

        return $schemas;
    }

    protected function expectedEntityFromOperationId(string $operationId): ?string
    {
        $parts = explode('::', $operationId, 2);
        $prefix = $parts[0] ?? '';
        if ($prefix === '') {
            return null;
        }

        return 'entity.' . $prefix;
    }

    protected function isValidOperationId(string $operationId): bool
    {
        $parts = explode('::', $operationId, 2);
        if (count($parts) !== 2) {
            return false;
        }

        return $parts[0] !== '' && $parts[1] !== '';
    }

    /**
     * @param array<string, mixed> $operation
     */
    protected function hasNonEmptyResponses(array $operation): bool
    {
        if (!isset($operation['responses']) || !is_array($operation['responses'])) {
            return false;
        }

        return $operation['responses'] !== [];
    }

    /**
     * @param array<string, mixed> $operation
     */
    protected function hasSuccessResponse(array $operation): bool
    {
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses)) {
            return false;
        }

        foreach ($responses as $code => $_response) {
            if (is_int($code) && $code >= 200 && $code < 300) {
                return true;
            }
            if (!is_string($code)) {
                continue;
            }
            if (preg_match('/^2\d\d$/', $code) === 1) {
                return true;
            }
            if (preg_match('/^2XX$/i', $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return list<string>
     */
    protected function responsesMissingDescription(array $operation): array
    {
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses)) {
            return [];
        }

        $missing = [];
        foreach ($responses as $statusCode => $response) {
            $code = is_int($statusCode) ? (string)$statusCode : (is_string($statusCode) ? $statusCode : '');
            if ($code === '') {
                continue;
            }
            if (!is_array($response)) {
                $missing[] = $code;
                continue;
            }
            $description = $response['description'] ?? null;
            if (!is_string($description) || $description === '') {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return list<string>
     */
    protected function responsesHasInvalidStatusKeys(array $operation): array
    {
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses)) {
            return [];
        }

        $invalid = [];
        foreach ($responses as $statusCode => $response) {
            if (!$this->isValidResponsesStatusKey($statusCode)) {
                $invalid[] = (string)$statusCode;
            }
        }

        return $invalid;
    }

    /**
     * @param array<string, mixed> $operation
     */
    protected function hasAnyResponsePayloadHint(array $operation): bool
    {
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses) || $responses === []) {
            return false;
        }

        foreach ($responses as $response) {
            if (!is_array($response)) {
                continue;
            }
            if (isset($response['$ref']) && is_string($response['$ref']) && $response['$ref'] !== '') {
                return true;
            }
            $content = $response['content'] ?? null;
            if (is_array($content) && $content !== []) {
                return true;
            }
        }

        return false;
    }

    protected function isValidResponsesStatusKey(mixed $statusCode): bool
    {
        if (is_int($statusCode)) {
            return $statusCode >= 100 && $statusCode <= 599;
        }

        if (!is_string($statusCode)) {
            return false;
        }

        $code = strtolower($statusCode);
        if ($code === 'default') {
            return true;
        }

        if (preg_match('/^\d{3}$/', $statusCode) === 1) {
            $int = (int)$statusCode;
            return $int >= 100 && $int <= 599;
        }

        // Wildcard form: 1XX/2XX/3XX/4XX/5XX
        return preg_match('/^[1-5]xx$/i', $statusCode) === 1;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return list<string>
     */
    protected function missingPathParameters(string $path, array $operation): array
    {
        $templateNames = $this->extractTemplatePathParameterNames($path);
        if ($templateNames === []) {
            return [];
        }

        $declared = [];
        $parameters = $operation['parameters'] ?? null;
        if (is_array($parameters)) {
            foreach ($parameters as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }
                if (($parameter['in'] ?? null) !== 'path') {
                    continue;
                }
                $name = $parameter['name'] ?? null;
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $declared[$name] = true;
            }
        }

        $missing = [];
        foreach ($templateNames as $name) {
            if (!isset($declared[$name])) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return list<string>
     */
    protected function pathParametersMustBeRequired(string $path, array $operation): array
    {
        $templateNames = $this->extractTemplatePathParameterNames($path);
        if ($templateNames === []) {
            return [];
        }

        $templateSet = [];
        foreach ($templateNames as $name) {
            $templateSet[$name] = true;
        }

        $warnings = [];
        $parameters = $operation['parameters'] ?? null;
        if (!is_array($parameters)) {
            return [];
        }

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            if (($parameter['in'] ?? null) !== 'path') {
                continue;
            }
            $name = $parameter['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (!isset($templateSet[$name])) {
                continue; // Only validate parameters that correspond to the path template.
            }

            if (($parameter['required'] ?? null) !== true) {
                $warnings[] = $name;
            }
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return list<string>
     */
    protected function unexpectedPathParameters(string $path, array $operation): array
    {
        $templateSet = [];
        foreach ($this->extractTemplatePathParameterNames($path) as $name) {
            $templateSet[$name] = true;
        }

        $unexpected = [];
        $parameters = $operation['parameters'] ?? null;
        if (!is_array($parameters)) {
            return [];
        }

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            if (($parameter['in'] ?? null) !== 'path') {
                continue;
            }
            $name = $parameter['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            if (!isset($templateSet[$name])) {
                $unexpected[] = $name;
            }
        }

        return $unexpected;
    }

    /**
     * @return list<string>
     */
    protected function extractTemplatePathParameterNames(string $path): array
    {
        if (preg_match_all('/\{([^{}]+)\}/', $path, $matches) < 1) {
            return [];
        }

        $out = [];
        foreach ($matches[1] as $name) {
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @param mixed $node
     *
     * @return list<string>
     */
    protected function collectRefs(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $out = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $out[] = $value;
                continue;
            }
            foreach ($this->collectRefs($value) as $ref) {
                $out[] = $ref;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $doc
     * @param string $pointerWithoutHash Slash-separated pointer without leading "#/"
     */
    protected function jsonPointerExists(array $doc, string $pointerWithoutHash): bool
    {
        if ($pointerWithoutHash === '') {
            return true;
        }

        $current = $doc;
        foreach (explode('/', $pointerWithoutHash) as $part) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            return false;
        }

        return true;
    }
}
