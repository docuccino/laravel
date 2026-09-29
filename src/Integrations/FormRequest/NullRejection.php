<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Validation\ValidationRule;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Rules\Enum;
use Throwable;

/**
 * Whether Laravel's validator rejects a key that is PRESENT with a null value — what a copied input holds
 * when the header or query value it copies was not sent, so the answer is whether that part is required.
 * The installed framework is asked rather than a table, because the answer moves between versions (13
 * type-checks `ascii` and the digit rules, which 12 lets a null through); `composer.lock` keys the cache.
 * Only rules whose verdict reads nothing but the value and their own parameters are asked; any other rule
 * adds no claim, so an unknown publishes the part optional — the wider claim, never a false one.
 */
final class NullRejection
{
    /**
     * Rules asked of the validator: their verdict on a null reads only the value, the rule's parameters and
     * the rule set itself. A file rule's I/O is the value's own, which a null has none of.
     *
     * @var list<string>
     */
    public const ASKED = [
        'accepted', 'alpha', 'alpha_dash', 'alpha_num', 'array', 'array_keys', 'ascii', 'bail', 'base64', 'between',
        'boolean', 'contains', 'date', 'date_format', 'decimal', 'declined', 'digits', 'digits_between', 'dimensions',
        'doesnt_contain', 'doesnt_end_with', 'doesnt_start_with', 'email', 'encoding', 'ends_with', 'extensions', 'file',
        'filled', 'hex_color', 'image', 'in', 'in_array_keys', 'integer', 'ip', 'ipv4', 'ipv6', 'json', 'list',
        'lowercase', 'mac_address', 'max', 'max_digits', 'mimes', 'mimetypes', 'min', 'min_digits', 'missing',
        'multiple_of', 'not_in', 'not_regex', 'nullable', 'numeric', 'present', 'prohibited', 'regex', 'required',
        'required_array_keys', 'size', 'sometimes', 'starts_with', 'string', 'timezone', 'ulid', 'uppercase', 'url',
        'uuid',
    ];

    /**
     * Rules never asked, each with why: at runtime they read another field, the clock, a store, the network or
     * the signed-in user, so a build has nothing to ask them with.
     *
     * @var array<string, string>
     */
    public const NOT_ASKED = [
        'accepted_if' => 'another field', 'active_url' => 'the network', 'after' => 'another field or the clock',
        'after_or_equal' => 'another field or the clock', 'before' => 'another field or the clock',
        'before_or_equal' => 'another field or the clock', 'confirmed' => 'another field',
        'current_password' => 'the signed-in user', 'date_equals' => 'another field or the clock',
        'declined_if' => 'another field', 'different' => 'another field', 'distinct' => 'the other items',
        'exclude' => 'excludes the key', 'exclude_if' => 'another field', 'exclude_unless' => 'another field',
        'exclude_with' => 'another field', 'exclude_without' => 'another field', 'exists' => 'a store',
        'gt' => 'another field', 'gte' => 'another field', 'in_array' => 'another field', 'lt' => 'another field',
        'lte' => 'another field', 'missing_if' => 'another field', 'missing_unless' => 'another field',
        'missing_with' => 'another field', 'missing_with_all' => 'another field', 'present_if' => 'another field',
        'present_unless' => 'another field', 'present_with' => 'another field', 'present_with_all' => 'another field',
        'prohibited_if' => 'another field', 'prohibited_if_accepted' => 'another field',
        'prohibited_if_declined' => 'another field', 'prohibited_unless' => 'another field',
        'prohibits' => 'another field', 'required_if' => 'another field', 'required_if_accepted' => 'another field',
        'required_if_declined' => 'another field', 'required_unless' => 'another field',
        'required_with' => 'another field', 'required_with_all' => 'another field',
        'required_without' => 'another field', 'required_without_all' => 'another field', 'same' => 'another field',
        'unique' => 'a store', 'with_bag' => 'not a rule',
    ];

    private static ?Factory $factory = null;

    /**
     * @param  list<ValidationRule>  $rules
     */
    public static function rejects(array $rules): bool
    {
        // `exclude_*` drops the key from validation on a condition read at runtime, before any rule runs —
        // the one kind of rule that lets through a null the others fail, so it cannot merely go unasked.
        foreach ($rules as $rule) {
            if (str_starts_with($rule->name, 'exclude')) {
                return false;
            }
        }

        // Each rule is asked alone first: one the installed validator cannot run (a rule a later major
        // added, a parameter it refuses) drops out rather than taking every other answer with it.
        $asked = [];
        foreach ($rules as $rule) {
            $spelled = self::spelled($rule);
            if ($spelled !== null && self::fails([$spelled]) !== null) {
                $asked[] = $spelled;
            }
        }

        // Then together, because rules answer as a set: `nullable` stops the others running on a null.
        return $asked !== [] && self::fails($asked) === true;
    }

    /**
     * The rule as the validator takes it, or null for one it is never asked.
     *
     * @return string|list<string>|Enum|null
     */
    private static function spelled(ValidationRule $rule): string|array|Enum|null
    {
        if ($rule->name === 'enum') {
            return is_string($rule->note) && enum_exists($rule->note) ? new Enum($rule->note) : null;
        }

        // `email:dns` looks the domain up, which is nothing a build may do.
        if (! in_array($rule->name, self::ASKED, true) || ($rule->name === 'email' && in_array('dns', $rule->parameters, true))) {
            return null;
        }

        // The array form hands each parameter over as it is, with no string to re-split.
        return $rule->parameters === [] ? $rule->name : [$rule->name, ...$rule->parameters];
    }

    /**
     * Whether the installed validator fails a present null under $rules, or null when it cannot say.
     *
     * @param  list<string|list<string>|Enum>  $rules
     */
    private static function fails(array $rules): ?bool
    {
        // A copy is only ever read off a FormRequest, so `laravel/framework`, and its validator, is installed.
        self::$factory ??= new Factory(new Translator(new ArrayLoader, 'en'));

        // Laravel 12 hands the null to string functions, which PHP reports as deprecated: the validator's
        // business, and not a warning the build should raise.
        set_error_handler(static fn (): bool => true);

        try {
            return self::$factory->make(['k' => null], ['k' => $rules])->fails();
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
