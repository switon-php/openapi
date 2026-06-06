# Switon OpenAPI Package

[![OpenAPI CI](https://img.shields.io/github/actions/workflow/status/switon-php/openapi/ci.yml?branch=main&label=OpenAPI%20CI)](https://github.com/switon-php/openapi/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's OpenAPI pipeline for collecting routes, merging YAML fragments, syncing stubs, and exporting JSON specs.

## Highlights

- **Document assembly:** route metadata and YAML fragments are merged into one OpenAPI document.
- **Route collection:** HTTP controllers can be scanned into OpenAPI entries.
- **Stub sync:** discovered routes can generate missing controller and component stubs.
- **CLI workflow:** `open-api:init`, `open-api:sync`, `open-api:export`, and `open-api:lint` cover the spec loop.
- **Spec helpers:** response schema guessing and sample-to-schema conversion reduce manual work.

## Installation

```bash
composer require switon/openapi
```

## Quick Start

```bash
bash bin/console open-api:init
bash bin/console open-api:sync
bash bin/console open-api:export
bash bin/console open-api:lint
```

Docs: https://docs.switon.dev/latest/openapi

## License

MIT.
