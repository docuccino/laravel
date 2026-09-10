<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

/**
 * One authorization requirement from a `spatie/laravel-permission` middleware: its type, the pipe-separated
 * any-of `values`, and the optional guard a `,guard` suffix names. Feeds the `x-permissions` member and the
 * description line.
 *
 * `values` is a SET — the pipe list is any-of, so naming one entry twice names one alternative — and the
 * constructor is what guarantees it, so no producer can hand the document `["edit", "edit"]`.
 */
final readonly class PermissionRequirement
{
    /** @var list<string> */
    public array $values;

    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $type,
        array $values,
        public ?string $guard = null,
    ) {
        $this->values = array_values(array_unique($values));
    }

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
     * A pipe list is any-of, so multi-value requirements say so explicitly rather than reading as a set
     * you need all of.
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
