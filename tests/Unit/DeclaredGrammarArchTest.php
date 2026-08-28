<?php

declare(strict_types=1);

/*
 * A hand-written type string is read with the AUTHOR's grammar.
 *
 * `TypeStringParser` has two readings of the same syntax, and one word parts company: `object` inferred
 * off PHP source is an instance of something whose wire shape is unknowable, while `object` written into
 * a `#[Response(type: '…')]` is the JSON word, said about the wire by the one person who knows. Every
 * declaration site in the adapter is therefore `parseDeclared()`, and a `parse()` there publishes an open
 * schema where the author promised an object — valid, self-consistent, and not what they wrote.
 *
 * It was wrong in seven places at once, so it is not a thing to remember and it is a thing to check.
 *
 * The scan is scoped to `php/laravel/src` — the adapter is where hand-written declarations are read.
 * `docuccino/inference-phpstan` is the other side of the same split: `Metadata\ClassMetadataFactory`
 * reads docblock type strings an ANALYSER is standing in for, so its plain `parse()` is correct and is
 * deliberately out of this scan's reach. And the receiver is typed rather than guessed: the adapter
 * calls `->parse()` on middleware parsers and on nikic/php-parser too, and a scan that read the method
 * name alone would condemn all of them.
 */

/**
 * Every call of one method on a `TypeStringParser` under a directory, as `relative/path.php:LINE`.
 *
 * @return list<string>
 */
function typeStringParserCallsIn(string $directory, string $method): array
{
    return sourceLinesIn($directory, static fn (string $source): array => typeStringParserCallLines($source, $method));
}

/**
 * The line of every `->$method(` call on a `TypeStringParser` in one source.
 *
 * Tokenised rather than grepped, for the reason the boundary scans tokenise: the receiver decides
 * whether a `parse()` is this one, and the names a receiver can carry are found by reading the file's
 * own declarations — a promoted or plain property, a parameter, a local, or the parser constructed
 * inline. A name in a string or a comment is not a call, which tokenising settles for free.
 *
 * @return list<int>
 */
function typeStringParserCallLines(string $source, string $method): array
{
    $tokens = significantTokens($source);

    $holders = [];
    foreach ($tokens as $index => $token) {
        if (! typeStringParserNamed($token)) {
            continue;
        }

        // `TypeStringParser $types` — a property, promoted or not, a parameter, or a typed local.
        $next = $tokens[$index + 1] ?? null;
        if ($next !== null && $next->is(T_VARIABLE)) {
            $holders[substr($next->text, 1)] = true;

            continue;
        }

        // `$parser = new TypeStringParser`, where the name follows rather than leads.
        if (($tokens[$index - 1] ?? null)?->is(T_NEW) === true
            && ($tokens[$index - 2]->text ?? null) === '='
            && ($tokens[$index - 3] ?? null)?->is(T_VARIABLE) === true) {
            $holders[substr($tokens[$index - 3]->text, 1)] = true;
        }
    }

    $lines = [];
    foreach ($tokens as $index => $token) {
        if (! $token->is(T_STRING) || $token->text !== $method || ($tokens[$index + 1]->text ?? null) !== '(') {
            continue;
        }

        if (($tokens[$index - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) !== true) {
            continue;
        }

        if (typeStringParserReceives($tokens, $index - 2, $holders)) {
            $lines[] = $token->line;
        }
    }

    return $lines;
}

/** Whether a token names the class — imported short, or written out. */
function typeStringParserNamed(PhpToken $token): bool
{
    return $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
        && (bool) preg_match('/(^|\\\\)TypeStringParser$/', $token->text);
}

/**
 * Whether the receiver ending at $index is a `TypeStringParser`: a known local (`$types->…`), a known
 * property of `$this` (`$this->types->…`), or one built on the spot (`(new TypeStringParser)->…`).
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, true>  $holders
 */
function typeStringParserReceives(array $tokens, int $index, array $holders): bool
{
    $receiver = $tokens[$index] ?? null;
    if ($receiver === null) {
        return false;
    }

    if ($receiver->is(T_VARIABLE)) {
        return isset($holders[substr($receiver->text, 1)]);
    }

    // `$this->types` — the property name, reached through the object operator before it.
    if ($receiver->is(T_STRING)
        && ($tokens[$index - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) === true
        && ($tokens[$index - 2]->text ?? null) === '$this') {
        return isset($holders[$receiver->text]);
    }

    // `(new TypeStringParser)` — the constructor, parenthesised so the call can chain off it.
    return $receiver->text === ')'
        && ($tokens[$index - 1] ?? null) !== null
        && typeStringParserNamed($tokens[$index - 1])
        && ($tokens[$index - 2] ?? null)?->is(T_NEW) === true;
}

it('reads no hand-written type string with the analyser grammar', function (): void {
    expect(typeStringParserCallsIn(dirname(__DIR__, 2).'/src', 'parse'))->toBe([]);
});

/**
 * A scan that matches nothing passes forever, so the count the assertion above is worth is stated: the
 * declaration sites are still there, still typed, and still reached by the scanner. Seven is the number
 * the sweep left behind — a plausible minimum, not a pin, so adding a declaration site is not a failure.
 */
it('is scanning something', function (): void {
    expect(count(typeStringParserCallsIn(dirname(__DIR__, 2).'/src', 'parseDeclared')))->toBeGreaterThanOrEqual(7);
});

/**
 * The scanner's own proof, in both directions: every way the adapter holds a parser, and the `->parse()`
 * calls on OTHER parsers that share the method name and must not be flagged.
 */
it('sees a parser call however the parser is held, and only those', function (): void {
    $source = <<<'PHP'
        <?php

        namespace Docuccino\Laravel\Sneaky;

        use Docuccino\Core\TypeGrammar\TypeStringParser;

        final class Sneaky
        {
            public const string ADVICE = 'never write $this->types->parse($type) here';

            public function __construct(
                private readonly TypeStringParser $types = new TypeStringParser,
                private readonly ScopeParser $scopes = new ScopeParser,
            ) {}

            public function run(string $type, ?TypeStringParser $given): void
            {
                $this->types->parse($type);
                $this->types?->parse($type);
                $given->parse($type);
                (new TypeStringParser)->parse($type);
                (new \Docuccino\Core\TypeGrammar\TypeStringParser)->parse($type);

                $local = new TypeStringParser;
                $local->parse($type);

                // $this->types->parse($type) in a comment is not a call.
                $this->types->parseDeclared($type);
                $this->scopes->parse($type);
                $this->parse($type);
                ScopeParser::parse($type);
                $given->parseDeclared($type);
            }

            private function parse(string $type): void {}
        }
        PHP;

    // Every line that reads a type string with the inferred grammar through a parser this file holds —
    // and nothing else: the declared calls, the middleware parser sharing the method name, the class's
    // own `parse()`, a static call, and the advice string are all somebody else's business.
    expect(typeStringParserCallLines($source, 'parse'))->toBe([18, 19, 20, 21, 22, 25])
        ->and(typeStringParserCallLines($source, 'parseDeclared'))->toBe([28, 32]);
});
