<?php

declare(strict_types=1);

namespace Switon\OpenApi\Command;

use Switon\Command\Attribute\Tool;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassName;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\PathAliasInterface;
use Switon\OpenApi\DocumentBuilderInterface;
use Switon\OpenApi\LinterInterface;
use Switon\OpenApi\YmlSyncInterface;
use Throwable;

use function count;
use function dirname;
use function rtrim;
use function str_ends_with;

/**
 * OpenAPI directory scaffold, YAML sync from routes, and JSON export.
 *
 * Guidance: use this CLI entrypoint to manage the full OpenAPI authoring loop from scaffold to sync, export, and lint.
 *
 * Road-signs:
 * - open-api:init
 * - open-api:sync
 * - open-api:export (--json → stdout)
 * - open-api:lint
 *
 * @see \Switon\OpenApi\DocumentBuilderInterface
 * @see \Switon\OpenApi\YmlSyncInterface
 */
class OpenApiCommand
{
    #[Autowired] protected ConsoleInterface $console;

    #[Autowired] protected FilesystemInterface $filesystem;

    #[Autowired] protected PathAliasInterface $pathAlias;

    #[Autowired] protected DocumentBuilderInterface $documentBuilder;

    #[Autowired] protected YmlSyncInterface $ymlSync;

    #[Autowired] protected LinterInterface $linter;

    /**
     * Creates the OpenAPI directory scaffold and optionally runs an initial sync pass.
     *
     * @param string $root Alias path (default <code>@root/openapi</code>)
     */
    #[Tool('[root] [--no-sync]. Creates openapi.yml + components/ + controllers/ layout; runs sync unless --no-sync.')]
    public function initAction(string $root = '@root/openapi', bool $no_sync = false): int
    {
        $dir = rtrim($this->pathAlias->resolve($root), '/');
        $entry = $dir . '/openapi.yml';
        if ($this->filesystem->exists($entry)) {
            return $this->console->error(
                'Already initialized (openapi.yml exists): {path}',
                ['path' => $entry],
            );
        }

        $base = $this->scaffoldBasePath();
        if (
            !$this->filesystem->exists($base . '/openapi.yml')
            || !$this->filesystem->exists($base . '/README.md')
        ) {
            return $this->console->error('OpenAPI scaffold files are missing from package: {path}', ['path' => $base]);
        }

        if (!$this->filesystem->exists($dir)) {
            $this->filesystem->mkdir($dir);
        }
        $this->filesystem->mkdir($dir . '/controllers');
        $this->filesystem->mkdir($dir . '/components');

        $this->writeScaffoldFile($base . '/openapi.yml', $entry);
        if ($this->filesystem->exists($base . '/recommended.example.yml')) {
            $this->writeScaffoldFile($base . '/recommended.example.yml', $dir . '/recommended.example.yml');
        }
        if ($this->filesystem->exists($base . '/components/entity.example.yml')) {
            $this->writeScaffoldFile($base . '/components/entity.example.yml', $dir . '/components/entity.example.yml');
        }
        $this->writeScaffoldFile($base . '/README.md', $dir . '/README.md');

        if (!$no_sync) {
            $code = $this->runSync($dir, '', '');
            if ($code !== 0) {
                return $code;
            }
        }

        $this->console->writeLn($dir);

        return 0;
    }

    /**
     * Writes missing controller YAML fragments from discovered attribute routes.
     *
     * @param string $root Alias path to OpenAPI root
     * @param string $controller Optional controller FQCN filter (exact match)
     * @param string $action Optional action method name filter (e.g. <code>indexAction</code>, exact match)
     * @param bool $tail <code>--tail</code>: append new path stubs at the end of the <code>paths</code> section (not lexicographic among siblings).
     */
    #[Tool('[root] [--controller=] [--action=] [--tail]. Sync: append missing YAML stubs; --tail = new paths at end of paths block.')]
    public function syncAction(
        string $root = '@root/openapi',
        string $controller = '',
        string $action = '',
        bool   $tail = false,
    ): int {
        $dir = rtrim($this->pathAlias->resolve($root), '/');
        $controller = ClassName::normalize($controller);

        return $this->runSync($dir, $controller, $action, $tail);
    }

    /**
     * Merges the OpenAPI fragments and exports the resulting document as JSON.
     *
     * @param string $root Alias path to OpenAPI root (default <code>@root/openapi</code>)
     * @param string $out Output file path (alias allowed); empty = <code>{root}/dist/openapi.json</code> when not using <code>--json</code> alone
     * @param bool $json Print the full merged OpenAPI document JSON to stdout (for pipes / post-processing). With <code>--out</code>, also writes that file; stdout stays document-only (no path line).
     * @param bool $deref Dereference local <code>#/...</code> refs before JSON output. Default off.
     * @param bool $keep_components Keep top-level <code>components</code> in dereferenced output; default behavior removes it.
     */
    #[Tool('[root] [--out=] [--json] [--deref] [--keep-components]. Merges openapi.yml + components/*.yml + controllers/*.yml; --deref resolves local #/... refs (default off) and removes top-level components unless --keep-components is set; --json prints full spec JSON on stdout (optional --out to write file too).')]
    public function exportAction(
        string $root = '@root/openapi',
        string $out = '',
        bool   $json = false,
        bool   $deref = false,
        bool   $keep_components = false,
    ): int {
        $dir = $this->pathAlias->resolve($root);
        if (!$this->filesystem->exists($dir) || !$this->filesystem->isDir($dir)) {
            return $this->console->error('OpenAPI root is not a directory: {path}', ['path' => $dir]);
        }
        try {
            $data = $this->documentBuilder->build($dir, $deref, $keep_components);
            $document = Json::stringify($data, JSON_PRETTY_PRINT);
        } catch (Throwable $e) {
            return $this->console->error('OpenAPI export failed: {message}', ['message' => $e->getMessage()]);
        }

        if ($json) {
            $this->console->write($document);
            if ($document !== '' && !str_ends_with($document, "\n")) {
                $this->console->write("\n");
            }
        }

        $writeFile = !$json || $out !== '';
        if ($writeFile) {
            $outPath = $out !== '' ? $this->pathAlias->resolve($out) : rtrim($dir, '/') . '/dist/openapi.json';
            $parent = dirname($outPath);
            if (!$this->filesystem->exists($parent)) {
                $this->filesystem->mkdir($parent);
            }
            $this->filesystem->write($outPath, $document);
            if (!$json) {
                $this->console->writeLn($outPath);
            }
        }

        return 0;
    }

    /**
     * Runs merged-document quality checks for local workflows and CI.
     *
     * @param string $root Alias path to OpenAPI root (default <code>@root/openapi</code>)
     * @param bool $strict Return non-zero when warnings exist
     */
    #[Tool('[root] [--strict]. Lint merged OpenAPI: duplicate operationId, broken $ref, expected entity schema presence; --strict fails on warnings too.')]
    public function lintAction(string $root = '@root/openapi', bool $strict = false): int
    {
        $dir = $this->pathAlias->resolve($root);
        if (!$this->filesystem->exists($dir) || !$this->filesystem->isDir($dir)) {
            return $this->console->error('OpenAPI root is not a directory: {path}', ['path' => $dir]);
        }

        try {
            $result = $this->linter->lint($dir);
        } catch (Throwable $e) {
            return $this->console->error('open-api:lint failed: {message}', ['message' => $e->getMessage()]);
        }

        foreach ($result['warnings'] as $warning) {
            $this->console->warning('[lint] ' . $warning);
        }
        foreach ($result['errors'] as $error) {
            $this->console->writeLn('[lint:error] ' . $error);
        }

        $errors = count($result['errors']);
        $warnings = count($result['warnings']);
        if ($errors > 0) {
            return $this->console->error(
                'open-api:lint failed: {errors} error(s), {warnings} warning(s).',
                ['errors' => $errors, 'warnings' => $warnings],
            );
        }
        if ($strict && $warnings > 0) {
            return $this->console->error(
                'open-api:lint failed in strict mode: {warnings} warning(s).',
                ['warnings' => $warnings],
            );
        }

        $this->console->success(
            'open-api:lint ok: {errors} error(s), {warnings} warning(s).',
            ['errors' => $errors, 'warnings' => $warnings],
        );

        return 0;
    }

    /** Returns the scaffold resource directory bundled with the package. */
    protected function scaffoldBasePath(): string
    {
        return $this->pathAlias->resolve('@switon.openapi.resources/scaffold');
    }

    /**
     * @param string $from Absolute path to scaffold source (read with <code>file_get_contents</code>)
     * @param string $to Resolved absolute path to write via {@see FilesystemInterface}
     */
    protected function writeScaffoldFile(string $from, string $to): void
    {
        $this->filesystem->write($to, $this->filesystem->read($from));
    }

    /**
     * @param string $dir Resolved absolute OpenAPI root directory
     *
     * @return int exit code
     */
    protected function runSync(string $dir, string $controller, string $action, bool $tail = false): int
    {
        if (!$this->filesystem->exists($dir) || !$this->filesystem->isDir($dir)) {
            return $this->console->error('OpenAPI root is not a directory: {path}', ['path' => $dir]);
        }

        try {
            $result = $this->ymlSync->sync($dir, $controller, $action, $tail);
        } catch (Throwable $e) {
            return $this->console->error('open-api:sync failed: {message}', ['message' => $e->getMessage()]);
        }

        foreach ($result['warnings'] as $warning) {
            $this->console->warning($warning);
        }
        foreach ($result['written'] as $path) {
            $this->console->writeLn($path);
        }
        if ($result['written'] === []) {
            $this->console->writeLn(
                'open-api:sync: nothing written (no matching routes, all YAML up to date, or skipped files — see warnings).',
            );
        }

        return 0;
    }
}
