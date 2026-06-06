# OpenAPI directory

This tree is created by `openapi:init`. Edit **YAML** here (by hand or with a formatter), then export a single JSON
document.

## Layout

| Path                      | Purpose                                                                                                                                 |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `openapi.yml`             | Entry: `openapi` version, `info`, `servers`, and shared schemas (default includes `response.simple` / `response.page`)                  |
| `components/*.yml`        | Shared reusable models (recommended one `entity.*` schema per file, e.g. `entity.user.yml`)                                             |
| `controllers/*.yml`       | One YAML per controller; **sync** names files `{handler-id}.yml` (handler id = segment before `::` in `operationId`, aligned with RBAC) |
| `dist/openapi.json`       | **Generated** by `open-api:export` — do not edit by hand                                                                                |
| `recommended.example.yml` | **Reference only** — recommended style in one file; **not** merged by export                                                            |

## Commands

From the project root (paths support aliases; default root is `@root/openapi`):

```bash
bin/console open-api:export
bin/console open-api:export @root/openapi --out=@root/openapi/dist/openapi.json
bin/console open-api:export @root/openapi --deref
bin/console open-api:export @root/openapi --deref --keep-components
bin/console open-api:lint @root/openapi
```

Scaffold this layout the first time:

```bash
bin/console open-api:init
bin/console open-api:init @root/openapi
```

## Merge rules

- Reads `openapi.yml` if present, then every `components/*.yml`, then every `controllers/*.yml` in sorted file name
  order (only `.yml`; `.yaml` files are ignored).
- **paths**: operations under the same path are merged; the **same path + HTTP method** in a later file **overrides** an
  earlier one.
- **components** (e.g. `schemas`): same key in a later file overrides.
- **Cross-file `$ref` is not resolved** — keep each controller YAML self-contained, or bundle externally before placing
  files here.

## Response wrappers

`openapi.yml` seeds two shared schemas:

- `#/components/schemas/response.simple` — normal response envelope (`code`, `message`, nullable `data`)
- `#/components/schemas/response.page` — paged envelope (`code`, `message`, `data.items`, `data.total`, ...)

`open-api:sync` keeps output minimal and adds commented `$ref` hints so you can switch stubs to these wrappers quickly.
It also auto-creates `openapi/components/entity.{handler-id-prefix}.yml` when missing (if the file already exists, sync
leaves it untouched).

## YAML notes

The framework uses the **Switon YAML subset** (`YamlReader`). Avoid unsupported syntax such as `{ }` flow mappings.
Multi-line text may use `|` / `>` (including common headers like `>-`, `|+`).

See the package readme: `packages/openapi/README.md` (or `vendor/switon/openapi/README.md`).
