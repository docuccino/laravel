<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use ReflectionClass;

/**
 * An `OperationExtension` whose declaration files the TEST owns, standing in for one written in an
 * application's own tree — so a row can edit it the way its author does, which is the edit the fragment
 * cache has to notice.
 *
 * Two files, because an extension's answer is written across its whole hierarchy: the class, and a trait
 * supplying half of what `handle()` writes. One process may declare a class once, so the edit is written
 * in two halves that stand for the one an application makes — the file on disk really does carry the new
 * body, and the already-loaded class reads its scratch value from a static this moves to match. Only the
 * file half reaches the cache. A row editing only the TRAIT therefore moves no published byte and must
 * still retire the fragment, so it asks the cache what it stored rather than what the document said.
 */
final class EditableOperationExtension
{
    /** The extension's FQCN. Outside the autoloader on purpose: {@see write()} is the only thing that declares it. */
    public const string EXTENSION = 'Docuccino\\Laravel\\Tests\\Temp\\EditableOperationExtension';

    /** The operation field the extension writes, so a row can read its answer out of the document. */
    public const string FIELD = 'x-scratch';

    /**
     * Write both files with the given scratch value and trait marker, load them if they aren't loaded,
     * and return the extension's own file — resolved, since reflection answers the real path and the temp
     * directory may be a symlink.
     */
    public static function write(string $value, string $marker = 'M1'): string
    {
        $trait = self::writeTrait($marker);
        $file = self::file();
        file_put_contents($file, self::body($value));

        if (! class_exists(self::EXTENSION, false)) {
            require $trait;
            require $file;
        }

        (new ReflectionClass(self::EXTENSION))->setStaticPropertyValue('scratch', $value);

        return (string) realpath($file);
    }

    /** Write only the trait's file — the hierarchy half of the same edit — and return it, resolved. */
    public static function writeTrait(string $marker): string
    {
        $file = self::traitFile();
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, self::traitBody($marker));

        return (string) realpath($file);
    }

    /**
     * Where the extension's file lives. Per process, since the class inside it can only be declared
     * once — two parallel workers each get their own.
     */
    public static function file(): string
    {
        return self::directory().'/EditableOperationExtension.php';
    }

    public static function traitFile(): string
    {
        return self::directory().'/WritesScratchPrefix.php';
    }

    private static function directory(): string
    {
        return sys_get_temp_dir().'/docuccino-editable-extension-'.getmypid();
    }

    private static function body(string $value): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Docuccino\\Laravel\\Tests\\Temp;

            use Docuccino\\Core\\Draft\\OperationDraft;
            use Docuccino\\Core\\Extensions\\Context\\RouteContext;
            use Docuccino\\Core\\Extensions\\Contracts\\OperationExtension;
            use Docuccino\\Core\\Extensions\\Contracts\\OperationPhase;
            use Docuccino\\Core\\Patch\\Contribution;

            final class EditableOperationExtension implements OperationExtension
            {
                use WritesScratchPrefix;

                public static string \$scratch = '{$value}';

                public function phase(): OperationPhase
                {
                    return OperationPhase::Finalize;
                }

                public function handle(OperationDraft \$operation, RouteContext \$context): void
                {
                    \$operation->set('x-scratch', \$this->scratchPrefix().self::\$scratch, Contribution::attribute());
                }
            }

            PHP;
    }

    private static function traitBody(string $marker): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Docuccino\\Laravel\\Tests\\Temp;

            trait WritesScratchPrefix
            {
                public function scratchPrefix(): string
                {
                    return '{$marker}:';
                }
            }

            PHP;
    }
}
