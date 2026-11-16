<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\SchemaCheck;

/**
 * OAS says a schema's `default` MUST conform to that schema: a client generator fills it in, and a value
 * the schema calls invalid is a request the client builds that its own validation rejects. Every
 * recorded document is read, so a producer that starts publishing one shows up here whichever
 * integration it is.
 */
it('records only defaults that conform to the schema they sit on', function (): void {
    $sites = 0;
    $violations = [];

    foreach (glob(golden('*.uir.json')) ?: [] as $path) {
        $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $check = new SchemaCheck(ContractIndex::fromArray($document));

        $walk = static function (mixed $node, array $segments) use (&$walk, &$sites, &$violations, $check, $path): void {
            if (! is_array($node)) {
                return;
            }

            // A schema node: a default beside a keyword that constrains it. A responses map's `default`
            // member is a Response object and sits beside none of these.
            $constrains = array_intersect(['type', '$ref', 'enum', 'items', 'properties', 'oneOf', 'anyOf', 'allOf', 'const'], array_keys($node));
            if (array_key_exists('default', $node) && $constrains !== []) {
                $sites++;
                foreach ($check->check(json_decode((string) json_encode($node['default'])), $segments, 'the default') as $violation) {
                    $violations[] = basename($path).' /'.implode('/', $segments).': '.$violation->message;
                }
            }

            foreach ($node as $key => $child) {
                if ($key !== 'x-docuccino') {
                    $walk($child, [...$segments, (string) $key]);
                }
            }
        };

        $walk($document, []);
    }

    expect($violations)->toBe([])
        // A walk that stopped recognising schema nodes would pass on nothing checked.
        ->and($sites)->toBeGreaterThanOrEqual(50);
});
