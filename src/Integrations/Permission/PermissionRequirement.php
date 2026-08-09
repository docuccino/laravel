<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

/**
 * One authorization requirement recovered from a `spatie/laravel-permission` middleware: its `type`
 * (`role` | `permission` | `role_or_permission`), the pipe-separated `values` it demands (any-of),
 * and the optional `guard` a `,guard` suffix names. Feeds both the `x-permissions` extension member
 * and the generated description line.
 */
final readonly class PermissionRequirement
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $type,
        public array $values,
        public ?string $guard = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type, 'values' => $this->values];
        if ($this->guard !== null) {
            $out['guard'] = $this->guard;
        }

        return $out;
    }

    /**
     * The human description line. A pipe list is any-of, so multi-value requirements say so
     * explicitly ("Requires any of these permissions: …") rather than reading as a required set.
     */
    public function describe(): string
    {
        [$singular, $plural] = match ($this->type) {
            'role' => ['role', 'roles'],
            'role_or_permission' => ['role or permission', 'roles or permissions'],
            default => ['permission', 'permissions'],
        };

        $label = count($this->values) > 1
            ? 'Requires any of these '.$plural
            : 'Requires '.$singular;

        return $label.': '.implode(', ', $this->values);
    }
}
