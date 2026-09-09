<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Core\Lint\SensitiveFieldLintOptions;
use Docuccino\Laravel\Extensions\RecordedExamplesExtension;

/**
 * Feeds the leakage safelist and heuristics into the environment digest (design §10), because they
 * decide whether a recorded example is PUBLISHED at all: {@see RecordedExamplesExtension} withholds a
 * body {@see ExampleRedaction} still finds a credential in, so safelisting a pointer adds an example to
 * an operation and dropping one takes it away — fragment bytes either way.
 *
 * Nothing else keyed them. The `lint` bag is deliberately TOP-LEVEL rather than per-document, so no
 * document's raw config bag holds it and `DocumentConfig::hash()` cannot see it; and the extension
 * carries the options inside a collaborator object, which the resolved-extension signature reads as
 * nothing but its class name. A value that reaches no key input is not the same defect as one keyed by
 * the wrong thing, and this is the shape that fits it: a document-wide fact read at handle time that no
 * route file reflects, which is exactly what this chain is for.
 *
 * Registered UNCONDITIONALLY, like the auth and gate contributors: this is the product's own config and
 * belongs to no package, so an application that never installed one still owes its fragments the key.
 *
 * What it does NOT cover, and need not: `lint.leakage.enabled`. Redaction is handed its options with the
 * switch unhonoured — turning a report off is not a request to publish credentials — so `enabled` reaches
 * no fragment, and the lint that does read it is a document transformer, re-run on every build.
 */
final class LeakageDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly SensitiveFieldLintOptions $options) {}

    public function digest(): string
    {
        // The safelist is consulted by membership, so its order changes no answer and is sorted away —
        // re-ordering the config bag should not cost anybody a rebuild. The heuristics table is the
        // opposite: a name matches when it CONTAINS a token and the first hit wins, so its order is part
        // of what it answers and goes in as written.
        //
        // A pointer and a token are both free text, so the separator is the contract's `"\0"`: a comma
        // digested `allow: ['/a,/b']` — the mis-spelling of a two-entry list — exactly as the two-entry
        // list it safelists nothing like.
        $allow = $this->options->allow;
        sort($allow);

        $parts = ['leakage-allow', ...$allow, 'leakage-patterns'];
        foreach ($this->options->patterns as $token => $label) {
            $parts[] = $token;
            $parts[] = $label;
        }

        return implode("\0", $parts);
    }
}
