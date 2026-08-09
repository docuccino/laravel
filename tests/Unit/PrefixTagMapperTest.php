<?php

declare(strict_types=1);

use Docuccino\Laravel\Tags\PrefixTagMapper;

it('maps by exact key before prefix', function (): void {
    $mapper = new PrefixTagMapper(['admin.users' => 'User Admin', 'admin.' => 'Admin']);

    expect($mapper->map('admin.users'))->toBe('User Admin');
});

it('maps by the first matching prefix', function (): void {
    $mapper = new PrefixTagMapper(['admin.' => 'Admin']);

    expect($mapper->map('admin.settings'))->toBe('Admin');
});

it('passes a tag through unchanged when nothing matches', function (): void {
    $mapper = new PrefixTagMapper(['admin.' => 'Admin']);

    expect($mapper->map('Forms'))->toBe('Forms');
});

it('is identity with an empty map', function (): void {
    expect((new PrefixTagMapper)->map('Forms'))->toBe('Forms');
});
