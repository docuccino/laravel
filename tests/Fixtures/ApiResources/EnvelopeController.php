<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

/**
 * Returns a resource whose base adds top-level members, at the root and nested inside another, two
 * named collections — one keeping Laravel's `toArray`, one whose `toArray` returns its own `data` key —
 * a resource whose every key is conditional, one setting Laravel's `$with` property, one of `(object)` casts.
 */
final class EnvelopeController
{
    public function show(): ReleaseResource
    {
        return new ReleaseResource((object) ['tag' => 'v1']);
    }

    public function nested(): ArticleResource
    {
        return new ArticleResource((object) []);
    }

    public function index(): ReleaseCollection
    {
        return new ReleaseCollection([]);
    }

    public function linked(): LinkedReleaseCollection
    {
        return new LinkedReleaseCollection([]);
    }

    public function sparse(): SparseResource
    {
        return new SparseResource((object) ['tag' => 'v1']);
    }

    public function configured(): WithPropertyResource
    {
        return new WithPropertyResource((object) ['tag' => 'v1']);
    }

    public function cast(): CastMetaResource
    {
        return new CastMetaResource((object) ['tag' => 'v1']);
    }
}
