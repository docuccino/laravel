<?php

declare(strict_types=1);

use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;

/*
 * `#[PathParameter]` is the one parameter attribute that cannot mint what it names: OpenAPI requires
 * every `in: path` parameter to correspond to a template variable, so a name outside the route's URI
 * would publish a document a validator rejects. It is withheld and — where the author wrote it on the
 * action — reported. These pin both halves, and that the withholding covers the case the report does
 * not.
 */

/**
 * `[the parameter keys the operation publishes, the diagnostics raised]` for one route template and
 * one attribute set. `$inherited` are the declarations that came from the controller class rather than
 * the action.
 *
 * @param  list<object>  $written
 * @param  list<object>  $inherited
 * @param  list<string>  $template
 * @return array{0: list<string>, 1: list<object>}
 */
function pathAttributeRun(array $written, array $template, array $inherited = []): array
{
    $attributes = new AttributeSet($written);
    foreach ($inherited as $attribute) {
        $attributes->add($attribute, inherited: true);
    }

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/posts/{post}'),
        actionRef: new ActionRef('', null, 'show'),
        attributes: $attributes,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
        pathParameters: $template,
    );

    $operation = new OperationDraft;
    (new AttributeParametersExtension)->handle($operation, $context);

    $names = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $names[] = $parameter->in.':'.$parameter->name;
    }

    return [$names, $context->components->diagnostics()];
}

it('publishes the segment the template has, and withholds the one it does not', function (array $written, array $published, int $reports): void {
    [$names, $diagnostics] = pathAttributeRun($written, ['post']);

    expect($names)->toBe($published)
        ->and(diagnosticsCoded($diagnostics, 'attribute.path-parameter-unmatched'))->toHaveCount($reports);
})->with([
    // The name is a segment of the URI: it documents the parameter, as it always has.
    'a name that matches' => [[new PathParameter('post', type: 'int')], ['path:post'], 0],
    // The mistake: nothing in the URI is called this, so publishing it would make the document invalid.
    'a name that matches nothing' => [[new PathParameter('postId', type: 'int')], [], 1],
    // A segment renamed in the route keeps its old spelling only in the attribute.
    'a name gone stale after a rename' => [[new PathParameter('article')], [], 1],
    // A half-written declaration. No template variable is the empty string.
    'an empty name' => [[new PathParameter('')], [], 1],
    // Repeatable, so the author really can write it twice — one mistake, said once.
    'the same name twice' => [[new PathParameter('postId'), new PathParameter('postId')], [], 1],
    // One of each: the working half still documents its segment.
    'a working one beside a broken one' => [[new PathParameter('post'), new PathParameter('postId')], ['path:post'], 1],
    // Only the path attribute is judged against the template — nothing else in the URI names a query key.
    'a query parameter, which the template says nothing about' => [[new QueryParameter('sort')], ['query:sort'], 0],
]);

it('withholds an inherited declaration too, and stays silent about it', function (): void {
    // A controller covering a segment only some of its actions have is the ordinary way a class-level
    // declaration is written, so an action without that segment is not a mistake to report. Withholding
    // is what keeps the document valid regardless of where the declaration was written — the report
    // never had to carry that job.
    [$names, $diagnostics] = pathAttributeRun([], ['post'], [new PathParameter('postId', type: 'int')]);

    expect($names)->toBe([])
        ->and(diagnosticsCoded($diagnostics, 'attribute.path-parameter-unmatched'))->toBe([]);
});

it('reports the action\'s own declaration even where the controller wrote the same mistake', function (): void {
    [, $diagnostics] = pathAttributeRun([new PathParameter('postId')], ['post'], [new PathParameter('postId')]);

    expect(diagnosticsCoded($diagnostics, 'attribute.path-parameter-unmatched'))->toHaveCount(1);
});

it('is an error, and names the segments the template does have', function (): void {
    [, $diagnostics] = pathAttributeRun([new PathParameter('postId')], ['post', 'comment']);

    $diagnostic = diagnosticsCoded($diagnostics, 'attribute.path-parameter-unmatched')[0];

    expect($diagnostic->severity)->toBe(Severity::Error)
        ->and($diagnostic->message)->toContain('#[PathParameter(name: "postId")]')
        ->and($diagnostic->message)->toContain('has no {postId} segment')
        ->and($diagnostic->message)->toContain('It has {post}, {comment}.')
        ->and($diagnostic->routeSignature)->toBe('GET api/posts/{post}');
});

it('says a route has no path parameters at all rather than naming an empty list', function (): void {
    [, $diagnostics] = pathAttributeRun([new PathParameter('postId')], []);

    expect(diagnosticsCoded($diagnostics, 'attribute.path-parameter-unmatched')[0]->message)
        ->toContain('It has no path parameters at all.');
});

it('escapes a segment name it did not write', function (): void {
    // A template variable and an attribute argument both come out of the application's own source, so
    // an escape sequence in either would steer the terminal the diagnostic is printed to.
    [, $diagnostics] = pathAttributeRun([new PathParameter("post\x1b[31m")], ["id\x07"]);

    $message = diagnosticsCoded($diagnostics, 'attribute.path-parameter-unmatched')[0]->message;

    expect($message)->toContain('post\\x1B[31m')
        ->and($message)->toContain('{id\\x07}')
        ->and($message)->not->toContain("\x1b")
        ->and($message)->not->toContain("\x07");
});
