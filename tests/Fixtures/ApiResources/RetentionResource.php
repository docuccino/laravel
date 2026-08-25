<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Docuccino\Attributes\Example;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one resource shape where a per-field declaration is reachable: a resource that declares REAL
 * properties whose names are also `toArray` keys. Legal and occasionally written, but not the idiom —
 * an idiomatic resource proxies a model through `@mixin` and publishes `toArray` keys that no property
 * backs, which is why `PersonaResource` beside it can carry nothing per field.
 *
 * `plan` contests its docblock with an attribute, so the precedence the two readers share is visible on
 * one property. Only ever reflected.
 */
final class RetentionResource extends JsonResource
{
    /**
     * The plan this policy belongs to.
     *
     * @example from-the-docblock
     */
    #[Example(value: 'from-the-attribute')]
    public string $plan = 'enterprise';

    /** @example 90 */
    public int $days = 90;

    /** @example n/a */
    public int $grace_days = 0;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'plan' => $this->plan,
            'days' => $this->days,
            'grace_days' => $this->grace_days,
        ];
    }
}
