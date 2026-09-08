<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\ConfiguredFlag;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Registry\IntegrationToggles;

/**
 * Every on/off switch Docuccino's own configuration carries, and the one diagnostic that says a key
 * held something else. {@see ConfiguredFlag} is the reading; this is the catalogue of what gets read,
 * and the report on what could not be.
 *
 * The catalogue is here rather than beside each reader because the readers are scattered and the
 * report is not: a switch is read where it is needed — at a container bind, inside a viewer request,
 * in a policy value object — and most of those places have nowhere to put a diagnostic. So the paths
 * and their defaults are stated once, {@see forDocument()} reports the document-scoped ones through
 * the build's config pass and {@see forInstall()} the rest alongside the engine report. The defaults
 * here are load-bearing rather than a copy: {@see LINT_DEFAULTS} is what the lint bindings and
 * {@see LeakageOptions} actually read, and {@see ENABLED_DEFAULT} is what the master switch reads.
 *
 * A refusal is a WARNING, on the same terms as its neighbours in {@see ConfigDiagnostics}: the build
 * did not merely ignore a switch nobody reads, it DISCARDED an instruction someone wrote — and it
 * cannot fire on anything but a value someone typed.
 *
 * @internal
 */
final class ConfiguredFlags
{
    /** The one diagnostic code for a key that holds no switch, wherever in the configuration it sits. */
    public const string CODE = 'config.not-a-switch';

    /** The master switch's default: an install that says nothing about it is on. */
    public const bool ENABLED_DEFAULT = true;

    /**
     * `lint.<rule>.enabled` => the rule's own answer when the key is absent, so a config file
     * predating a rule keeps whatever shipped with it. Read by the lint bindings and by
     * {@see LeakageOptions}, which is what keeps this table from drifting away from them.
     *
     * @var array<string, bool>
     */
    public const array LINT_DEFAULTS = [
        'leakage' => true,
        'descriptions' => false,
        'operation_ids' => true,
        'tags' => false,
        'vacuous_union' => true,
        'examples' => true,
        'unpinned_redirect' => true,
    ];

    /**
     * Switches inside a `documents.<key>` bag, as dotted path => the default their reader uses.
     * `integrations.<name>.enabled` is not here: its default is per-integration and
     * {@see IntegrationToggles} owns it.
     *
     * @var array<string, bool>
     */
    private const array DOCUMENT_FLAGS = [
        'representation.enums.components' => true,
        'representation.errors.components' => true,
        'representation.pagination.components' => true,
        'routes.include_vendor' => false,
        'viewer.cdn' => false,
    ];

    /**
     * Switches outside every document, as dotted path => default. The master switch and the
     * `lint.<rule>.enabled` family are read off their own constants above.
     *
     * @var array<string, bool>
     */
    private const array INSTALL_FLAGS = [
        'cache.enabled' => false,
    ];

    /** The master switch, read the one way: absent is on, and only `false` turns it off. */
    public static function enabled(): bool
    {
        return self::read(self::config(), 'enabled', self::ENABLED_DEFAULT)->on;
    }

    /**
     * One `lint.<rule>.enabled` switch, out of that rule's own `lint.<rule>` bag.
     *
     * @param  key-of<self::LINT_DEFAULTS>  $rule
     * @param  array<string, mixed>  $bag
     */
    public static function lintEnabled(string $rule, array $bag): bool
    {
        return ConfiguredFlag::read($bag, 'enabled', self::LINT_DEFAULTS[$rule])->on;
    }

    /**
     * Every switch in this document's own bag that holds no switch, as diagnostics — the fixed paths
     * above plus one per integration bag, whose default the toggle table answers.
     *
     * @return list<Diagnostic>
     */
    public static function forDocument(DocumentConfig $document): array
    {
        $diagnostics = [];

        foreach (self::DOCUMENT_FLAGS as $path => $default) {
            $diagnostic = self::report($document->raw, $path, $default);
            if ($diagnostic !== null) {
                $diagnostics[] = $diagnostic;
            }
        }

        foreach (IntegrationToggles::descriptors() as $descriptor) {
            $refusal = $document->integrationRefusal($descriptor->key, $descriptor->defaultEnabled);
            if ($refusal !== null) {
                $diagnostics[] = self::diagnostic($refusal);
            }
        }

        return $diagnostics;
    }

    /**
     * The switches outside any document: the master one, the fragment cache and every lint rule. Read
     * off the live config rather than a document bag, and reported once per build.
     *
     * @return list<Diagnostic>
     */
    public static function forInstall(): array
    {
        $config = self::config();
        $paths = ['enabled' => self::ENABLED_DEFAULT, ...self::INSTALL_FLAGS];

        foreach (self::LINT_DEFAULTS as $rule => $default) {
            $paths['lint.'.$rule.'.enabled'] = $default;
        }

        $diagnostics = [];
        foreach ($paths as $path => $default) {
            $diagnostic = self::report($config, $path, $default);
            if ($diagnostic !== null) {
                $diagnostics[] = $diagnostic;
            }
        }

        return $diagnostics;
    }

    /**
     * The refusal at one dotted path, or null when the key holds a switch (or nothing).
     *
     * @param  array<string, mixed>  $config
     */
    private static function report(array $config, string $path, bool $default): ?Diagnostic
    {
        $refusal = self::read($config, $path, $default)->refusal($path);

        return $refusal === null ? null : self::diagnostic($refusal);
    }

    /**
     * One reading of a DOTTED path: walk down to the bag the leaf sits in, then read the leaf.
     *
     * @param  array<string, mixed>  $config
     */
    private static function read(array $config, string $path, bool $default): ConfiguredFlag
    {
        $segments = explode('.', $path);
        $leaf = (string) array_pop($segments);

        $bag = $config;
        foreach ($segments as $segment) {
            $bag = Hydrate::map($bag[$segment] ?? null);
        }

        return ConfiguredFlag::read($bag, $leaf, $default);
    }

    private static function diagnostic(string $refusal): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: self::CODE,
            message: $refusal,
            help: ConfiguredFlag::HELP,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('docuccino', []);

        return $config;
    }
}
