<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormRequest;

use Closure;

/**
 * A class whose `rules()` returns two fields with unrecoverable (closure) rules — `file` (which a
 * type signal documents elsewhere) and `secret` (which nothing else documents). Used to prove the
 * `validation.rule-unrecoverable` diagnostic is suppressed for a field already documented by another
 * producer, while still firing for a genuinely-omitted field. Only ever reflected; its trace is
 * scripted in the test.
 */
final class SuppressibleRulesData
{
    /**
     * @return array<string, list<Closure>>
     */
    public function rules(): array
    {
        return [
            'file' => [static fn (): bool => true],
            'secret' => [static fn (): bool => true],
        ];
    }
}
