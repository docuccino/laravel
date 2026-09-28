<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

/**
 * A stored kind of media. Only ever reflected — never queried.
 *
 * @property int $id
 */
final class Photo extends Media {}
