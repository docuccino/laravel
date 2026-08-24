<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Example;
use Docuccino\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Workbench\App\Data\WidgetData;

/**
 * One action per way an `#[Example]` can fail to describe an example, so the suite can pin what each
 * degradation publishes and reports. Every action documents the same 200 body, so a row that fails is
 * failing on its declaration and not on having nothing to hang one off.
 */
#[Response(status: 200, type: WidgetData::class, description: 'The widget.')]
final class ExampleDegradationsController
{
    #[Example(name: 'nothing')]
    public function noValue(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'both', value: ['id' => 1], file: 'docuccino-example.json')]
    public function twoValues(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'everywhere', value: ['id' => 1], status: 200, request: true)]
    public function twoTargets(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(value: ['id' => 1], summary: 'No name to carry it')]
    public function unnamedSummary(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'teapot', value: ['id' => 1], status: 418)]
    public function unknownStatus(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'xml', value: ['id' => 1], mediaType: 'application/xml')]
    public function unknownMediaType(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'nope', value: ['id' => 1], parameter: 'nope')]
    public function unknownParameter(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'body', value: ['id' => 1], request: true)]
    public function noRequestBody(): JsonResponse
    {
        return response()->json([]);
    }

    // Both bodies satisfy WidgetData: which declaration wins is what this pins, so an incomplete
    // example would only add an unrelated lint.example-mismatch on top of the answer.
    #[Example(name: 'twice', value: ['id' => 1, 'name' => 'Cog', 'status' => 'draft'])]
    #[Example(name: 'twice', value: ['id' => 2, 'name' => 'Sprocket', 'status' => 'draft'])]
    public function duplicateName(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'escape', file: '../../../../../../etc/passwd')]
    public function escapingFile(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'absent', file: 'docuccino-example-absent.json')]
    public function missingFile(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'broken', file: 'docuccino-example-broken.json')]
    public function malformedJson(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'broken', file: 'docuccino-example-broken.yaml')]
    public function malformedYaml(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'plain', file: 'docuccino-example.txt')]
    public function unsupportedFormat(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'nothing-at-all', file: 'docuccino-example-null.json')]
    public function emptyFile(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'named', value: ['id' => 1, 'name' => 'Cog', 'status' => 'draft'])]
    #[Example(value: ['id' => 2, 'name' => 'Sprocket', 'status' => 'draft'])]
    public function namedAndUnnamed(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'not-a-number', value: ['ratio' => NAN])]
    public function nonFiniteValue(): JsonResponse
    {
        return response()->json([]);
    }

    #[Example(name: 'from-yaml', file: 'docuccino-example-nan.yaml')]
    public function unpublishableFile(): JsonResponse
    {
        return response()->json([]);
    }

    // Well-formed, documented, and a lie about the body: no attribute rule can catch that, so it is
    // the example audit the build runs over the finished document that has to.
    #[Example(name: 'wrong-shape', value: ['id' => 'not a number', 'name' => 'Sprocket'])]
    public function mismatchedValue(): JsonResponse
    {
        return response()->json([]);
    }
}
