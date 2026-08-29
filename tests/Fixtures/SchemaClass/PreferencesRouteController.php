<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SchemaClass;

/**
 * Actions type-hinting the two request types above, so the FormRequest-class lookup finds them on a
 * real route. Only ever reflected.
 */
final class PreferencesRouteController
{
    public function index(ReadOnlyFilterRequest $request): void {}

    public function list(SharedPreferencesRequest $request): void {}

    public function store(SharedPreferencesRequest $request): void {}
}
