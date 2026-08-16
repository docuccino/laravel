<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Data;

/**
 * A shared base carrying `#[MergeValidationRules]` for every request Data class under it — the shape an
 * app writes it in. PHP does not inherit class attributes, but spatie reads them off the whole parent
 * chain, so a subclass of this merges its `rules()` override. Only ever reflected.
 */
#[MergeValidationRules]
abstract class BaseApiData extends Data {}
