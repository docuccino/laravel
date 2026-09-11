<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

/**
 * An application's own mapper fixed on a base, which is where a convention shared by many payloads is
 * naturally written. Nothing static says what `map()` returns, so the subclass's keys degrade — and
 * the degradation is only reported if the read looks past the subclass for the attribute.
 */
#[MapName(ScreamingNameMapper::class)]
abstract class ScreamingAncestorData extends Data {}
