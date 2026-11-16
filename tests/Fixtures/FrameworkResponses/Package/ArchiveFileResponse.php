<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** A package's file response, serving an archive from disk. */
class ArchiveFileResponse extends BinaryFileResponse {}
