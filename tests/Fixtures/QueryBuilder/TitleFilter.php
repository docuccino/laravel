<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * Filters by a slug under a name of the trait's own; a class takes it as its `__invoke`.
 */
trait FiltersBySlug
{
    public function bySlug(Builder $query, mixed $value, string $property): void
    {
        $query->where('slug', $value);
    }
}

/**
 * Three custom filters sharing one file, each filtering on a different column — two write `__invoke`
 * themselves, one takes it from a trait under an alias. A body is only evidence about the class PHP
 * calls it on.
 *
 * @implements Filter<Model>
 */
final class TitleFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where('title', $value);
    }
}

/**
 * @implements Filter<Model>
 */
final class SlugFilter implements Filter
{
    use FiltersBySlug { bySlug as __invoke; }
}

/**
 * @implements Filter<Model>
 */
final class ScoreBandFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->where('score', $value);
    }
}
