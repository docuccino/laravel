# docuccino/laravel

[![Latest version](https://img.shields.io/packagist/v/docuccino/laravel?label=packagist)](https://packagist.org/packages/docuccino/laravel)
[![Downloads](https://img.shields.io/packagist/dt/docuccino/laravel)](https://packagist.org/packages/docuccino/laravel)
[![PHP version](https://img.shields.io/packagist/dependency-v/docuccino/laravel/php)](https://packagist.org/packages/docuccino/laravel)
[![Laravel version](https://img.shields.io/packagist/dependency-v/docuccino/laravel/illuminate%2Fsupport?label=laravel)](https://packagist.org/packages/docuccino/laravel)
[![CI](https://github.com/docuccino/docuccino/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/docuccino/docuccino/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/docuccino/laravel)](LICENSE)

**Automatic OpenAPI documentation for Laravel APIs, generated from the real types in your code.**

Docuccino reads your routes, form requests, API resources, Eloquent models and exception handling,
and writes an OpenAPI 3.2 or 3.1 document — including the error responses your application actually
returns. You get a Swagger-style reference you can serve, host anywhere, or gate your CI on, without
hand-writing annotations to get started.

```bash
composer require docuccino/laravel
composer require --dev docuccino/inference-phpstan
php artisan docuccino:install
```

Then open the bundled **Scalar** viewer at `/docs/api`.

## What you get

- **Error responses without configuration** — read from your app's real exception handling (render
  callbacks, `render()`, `Responsable::toResponse()`), with the thrown type narrowed so `instanceof`
  branches resolve.
- **Query Builder parameters, recovered** — `allowedFilters`, `allowedSorts` and `allowedIncludes`
  folded through helper methods several calls deep, with pagination parameters when the call graph
  reaches a paginating terminal.
- **A semantic diff you can gate on** — `docuccino:diff --enforce` compares two documents over stable
  identities and fails the build when a breaking change ships without the version bump its policy
  requires.
- **Byte-deterministic output** — identical code produces identical bytes, so a regenerated document
  never drifts and a diff only shows what really changed.
- **Provenance for every field** — `docuccino:explain "POST /api/invoices"` prints which layer
  produced each value and what it overrode.

## Install

Where your docs need to be readable decides how you install.

**Serve docs from your app** — the viewer live on a deployed environment:

```bash
composer require docuccino/laravel
composer require --dev docuccino/inference-phpstan   # powers type inference
```

**Docs in development only**, or a document you host elsewhere (ReadMe, Bump.sh, any OpenAPI host) —
keep both dev-only, so `composer install --no-dev` ships neither:

```bash
composer require --dev docuccino/laravel docuccino/inference-phpstan
```

Analysis is a build-time job either way: the inference engine runs wherever you generate the
document, never on a production host. Without the engine, documentation comes from docblocks and
attributes only — and every export warns that it did.

Then:

```bash
php artisan docuccino:install
```

It publishes `config/docuccino.php` (never replacing one you already have), reports how many of your
routes the default `api/*` pattern matches and which prefixes they sit under when none do, says
whether the engine is installed, and offers a first export.

## Usage

Generate and export the default document:

```bash
php artisan docuccino:export
```

Other commands: `docuccino:install`, `docuccino:diff`, `docuccino:validate`, `docuccino:cache`,
`docuccino:clear`, and `docuccino:explain "POST /api/invoices"` — which prints, field by field, which
precedence layer produced each value of one endpoint and what it overrode.

Register your own extensions from any service provider:

```php
use Docuccino\Laravel\Facades\Docuccino;

Docuccino::extend(MyOperationExtension::class);
```

## Contract testing

`Docuccino\Laravel\Testing\AssertsApiContract` holds the requests and responses your test suite
already produces to the document Docuccino generates, and a failure names the producer and the
`file:line` the schema came from. It also reports the documented endpoints your suite never
exercises, validates every published example against its own schema, and gates breaking changes and
a stale committed artifact.

```php
$this->getJson('/api/invoices')->assertValidExchange();
```

`ApiContract::record()` turns the same seam into examples: the responses your suite produced are
written to committed files, keyed by operation id and with credentials replaced, which the build
reads. The document build still executes nothing — the execution happened in your tests.

Test-only: nothing registers it, and the service provider never touches it. See
[contract testing](https://docs.docuccino.app/laravel/guides/contract-testing/).

## Part of Docuccino

| Package | Role |
| --- | --- |
| **`docuccino/laravel`** ← you are here | The Laravel adapter: provider, config, commands, viewer, integrations. |
| [`docuccino/core`](https://packagist.org/packages/docuccino/core) | Framework-agnostic document model, canonicalizer, identities, emitters, diff. |
| [`docuccino/inference-phpstan`](https://packagist.org/packages/docuccino/inference-phpstan) | PHPStan + Larastan type inference. Install as a **dev** dependency. |
| [`docuccino/attributes`](https://packagist.org/packages/docuccino/attributes) | Dependency-free PHP attribute classes. |

## Documentation

Full documentation is at **[docs.docuccino.app](https://docs.docuccino.app)**:

- [Getting started](https://docs.docuccino.app/laravel/getting-started/)
- [Configuration reference](https://docs.docuccino.app/laravel/reference/configuration/)
- [Commands](https://docs.docuccino.app/laravel/reference/commands/) ·
  [Attributes](https://docs.docuccino.app/laravel/reference/attributes/)
- [Package support](https://docs.docuccino.app/laravel/packages/)
- [Writing an integration](https://docs.docuccino.app/extending/extension-authoring/)
- Comparisons: [vs Scramble](https://docs.docuccino.app/guides/vs-scramble/) ·
  [vs Scribe](https://docs.docuccino.app/guides/vs-scribe/)

## Issues and contributing

**This repository is a read-only subtree split** of
[docuccino/docuccino](https://github.com/docuccino/docuccino). Open issues and pull requests on the
monorepo — commits pushed here are overwritten. See
[CONTRIBUTING.md](https://github.com/docuccino/docuccino/blob/main/CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
