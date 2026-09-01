<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning\Scaffold;

use Docuccino\Attributes\Versioning\ApiVersionChange;

/**
 * The template a scaffolded change class is written from: the one the application published if it has
 * one, else the one this package ships.
 *
 * Laravel's own convention, and deliberately not a config key. A stub is a file an author edits, so
 * the file being there IS the statement that they want theirs — `vendor:publish --tag=docuccino-stubs`
 * puts a copy where they can edit it, and a copy they delete puts the packaged one back. Nothing to
 * set, nothing to unset, and nothing folded into a `configHash` for a template that changes no byte of
 * any document.
 *
 * Placeholders are documented in the commands reference, because a stub whose variables nobody wrote
 * down is customisable only by reading this class. Both Laravel spellings are accepted — `{{ class }}`
 * and `{{class}}` — since the framework's own stubs mix them and an author editing one should not have
 * to know which.
 *
 * @internal
 */
final readonly class ChangeStub
{
    /** The file name, in the packaged directory and in the published one alike. */
    public const string NAME = 'version-change.stub';

    /** Where a published copy lives, under the application root. */
    public const string PUBLISHED_DIRECTORY = 'stubs/docuccino';

    public function __construct(private string $basePath) {}

    /** The packaged stub, which is also what `vendor:publish` copies. */
    public static function packaged(): string
    {
        return dirname(__DIR__, 3).'/stubs/'.self::NAME;
    }

    /** The stub in force: the application's, else the packaged one. */
    public function path(): string
    {
        $published = rtrim($this->basePath, '/').'/'.self::PUBLISHED_DIRECTORY.'/'.self::NAME;

        return is_file($published) ? $published : self::packaged();
    }

    /** Whether the stub in force is the application's own — what the command reports. */
    public function published(): bool
    {
        return $this->path() !== self::packaged();
    }

    /**
     * `$change` written out. Returns null when the stub cannot be read at all, which the caller reports
     * rather than writing a file from an empty template.
     */
    public function render(ScaffoldedChange $change, string $namespace): string|false
    {
        $stub = @file_get_contents($this->path());

        if ($stub === false) {
            return false;
        }

        return $this->fill($stub, [
            'namespace' => $namespace,
            'class' => $change->class,
            'since' => self::escaped($change->since),
            'description' => self::escaped($change->description),
            'imports' => $this->imports($change),
            'verbs' => implode("\n", [...$change->scope, $change->verb]),
        ]);
    }

    /**
     * The `use` lines, sorted, with {@see ApiVersionChange} always among them — every change declares
     * one, so the stub can rely on the short name being imported.
     */
    private function imports(ScaffoldedChange $change): string
    {
        $imports = [ApiVersionChange::class, ...$change->imports];
        $imports = array_values(array_unique($imports));
        sort($imports, SORT_STRING);

        return implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $stub, array $values): string
    {
        foreach ($values as $name => $value) {
            $stub = str_replace(['{{ '.$name.' }}', '{{'.$name.'}}'], $value, $stub);
        }

        return $stub;
    }

    /**
     * A value on its way into a single-quoted PHP string. Field names and descriptions come off an
     * artifact nobody validated, so a stray quote would be a syntax error in the file this writes —
     * and the class would then not load, which reads as a change that was never declared.
     */
    private static function escaped(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
