<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MockHints;

/**
 * One route returning the hinted DTO, and one taking the hinted request class — the response and
 * request halves of `#[Mock]` in a real build.
 */
final class ProfileController
{
    public function show(): array
    {
        return [];
    }

    public function store(ProfileRequest $request): array
    {
        return [];
    }

    public function index(ProfileRequest $request): array
    {
        return [];
    }
}
