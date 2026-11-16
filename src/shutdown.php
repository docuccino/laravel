<?php

declare(strict_types=1);

use Docuccino\Laravel\Engine\OutOfMemoryNotice;

// Loaded by Composer's autoloader before any application boots, so this is the first shutdown function the
// process has: an out-of-memory fatal still gets explained when the framework's error handler, registered
// after it, would run out reporting it (OutOfMemoryNotice). Inert unless a console build armed the notice,
// and never loads the class to find out.
if (PHP_SAPI === 'cli') {
    register_shutdown_function(static function (): void {
        if (class_exists(OutOfMemoryNotice::class, false)) {
            OutOfMemoryNotice::shutdown();
        }
    });
}
