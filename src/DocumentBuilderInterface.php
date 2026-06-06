<?php

declare(strict_types=1);

namespace Switon\OpenApi;

/**
 * Builds OpenAPI document arrays from split YAML fragments under one root directory.
 *
 * Guidance: Put shared schemas under <code>components/*.yml</code> (recommended one <code>entity.*</code> per file) and endpoint operations under <code>controllers/*.yml</code>. Prefer <code>operationId</code> equal to {@see \Switon\Routing\HandlerIdInterface::getId()} (see {@see \Switon\OpenApi\OperationIdInterface}) so docs align with RBAC.
 *
 * @see \Switon\OpenApi\DocumentBuilder
 * @see \Switon\OpenApi\Command\OpenApiCommand
 */
interface DocumentBuilderInterface
{
    /**
     * Build one OpenAPI document from <code>openapi.yml</code> + <code>components/*.yml</code> + <code>controllers/*.yml</code>.
     *
     * Later files override same path + HTTP method keys. Shallow merge for <code>components</code> sub-keys (<code>schemas</code>, …).
     *
     * @param string $root Absolute filesystem path to OpenAPI root (trailing slash optional)
     * @param bool $deref Dereference local <code>#/...</code> refs before returning
     * @param bool $keepComponents Keep top-level <code>components</code> when <code>$deref</code> is true
     *
     * @return array<string, mixed>
     */
    public function build(string $root, bool $deref = false, bool $keepComponents = false): array;
}
