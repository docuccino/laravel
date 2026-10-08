<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

/**
 * An answer to a puzzle, one shape per kind.
 *
 * @phpstan-sealed CountAnswer|MeasureAnswer|LettersAnswer
 */
interface Answer {}
