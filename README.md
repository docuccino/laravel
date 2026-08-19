# docuccino/laravel

> **This repository is a read-only subtree split** of [docuccino/docuccino](https://github.com/docuccino/docuccino).
> Open issues and pull requests on the monorepo — commits pushed here are overwritten.

The Laravel adapter for [Docuccino](https://docuccino.app) — a UIR-based API
documentation generator. It discovers your routes, runs the extension pipeline,
and exports OpenAPI / UIR documents from the real shape of your code.

## Install

To serve the docs from your app — the viewer live on a deployed environment:

```bash
composer require docuccino/laravel
composer require --dev docuccino/inference-phpstan   # powers type inference
```

For docs you only read in development, or a spec you host elsewhere, keep both dev-only —
`composer install --no-dev` then ships neither:

```bash
composer require --dev docuccino/laravel docuccino/inference-phpstan
```

Analysis is a build-time job either way: the inference engine runs wherever you generate
the document, never on a production host. Without the engine, documentation comes from
docblocks and attributes only — and every export warns that it did.

Then set up:

```bash
php artisan docuccino:install
```

It publishes `config/docuccino.php` (never replacing one you already have), reports how many of your
routes the default `api/*` pattern matches and which prefixes they sit under when none do, says
whether the engine is installed, and offers a first export. `vendor:publish --tag=docuccino-config`
still publishes the config on its own.

## Usage

Generate and export the default document:

```bash
php artisan docuccino:export
```

Other commands: `docuccino:install`, `docuccino:diff`, `docuccino:validate`, `docuccino:cache`,
`docuccino:clear`, and `docuccino:explain "POST /api/invoices"` — which prints, field by field, which
precedence layer produced each value of one endpoint and what it overrode. Register your own
extensions from any service provider:

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

## Documentation

Full documentation is at <https://docs.docuccino.app>:

- [Getting started](https://docs.docuccino.app/laravel/getting-started/)
- [Configuration reference](https://docs.docuccino.app/laravel/reference/configuration/)
- [Commands](https://docs.docuccino.app/laravel/reference/commands/) ·
  [Attributes](https://docs.docuccino.app/laravel/reference/attributes/)
- [Integrations](https://docs.docuccino.app/laravel/packages/)
- [Writing an integration](https://docs.docuccino.app/extending/extension-authoring/) ·
  [Docuccino vs Scramble](https://docs.docuccino.app/guides/vs-scramble/)

## License

MIT. See [LICENSE](LICENSE).
