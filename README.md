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

Publish the config:

```bash
php artisan vendor:publish --tag=docuccino-config
```

## Usage

Generate and export the default document:

```bash
php artisan docuccino:export
```

Other commands: `docuccino:diff`, `docuccino:validate`, `docuccino:cache`,
`docuccino:clear`. Register your own extensions from any service provider:

```php
use Docuccino\Laravel\Facades\Docuccino;

Docuccino::extend(MyOperationExtension::class);
```

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
